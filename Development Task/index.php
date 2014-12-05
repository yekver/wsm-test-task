<?php
/**
 * BaseCamp Web Site Movers class
 *
 * @required 	php CURL extension
 * @author 		Ivan Panteleev (yekver@gmail.com)
 */
class WSM_BC {
	protected $url = 'https://websitemovers.basecamphq.com';
	protected $username = 'ENTER_USER_NAME_HERE';
	protected $password = 'ENTER_PASSWORD_HERE';
	protected $project_id;
	protected $uri;
	private $db;

	/**
	 * @param int $project_id
	 * @param boolean $sync_flag : needed during the first executing
	 */
	public function __construct($project_id, $sync_flag) {
		$this->project_id = $project_id;
		$this->db = new DB();

		if ($sync_flag) {
			$this->sync_project();
		}

		$this->compare();
	}

	/**
	 * Synchronize current project state without creating new messages and comments
	 */
	private function sync_project() {
		list($lists, $items) = $this->get_project_data();
		if ($this->db->sync_project($this->project_id, $lists, $items)) {
			echo 'Project successfully synchronized with remote. <br>';
		}
	}

	private function connect($curl_opts = array()) {
		$session = curl_init();

		curl_setopt($session, CURLOPT_USERPWD, "{$this->username}:{$this->password}");
		curl_setopt($session, CURLOPT_URL, $this->url . $this->uri);
		curl_setopt($session, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
		curl_setopt($session, CURLOPT_HTTPHEADER, array('Accept: application/xml', 'Content-Type: application/xml'));
		curl_setopt($session, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($session, CURLOPT_SSL_VERIFYPEER, false);

		foreach($curl_opts as $key => $val) {
			curl_setopt($session, $key, $val);
		}

		try {
			$response = curl_exec($session);

			/* Check for 404 (file not found). */
			$httpCode = curl_getinfo($session, CURLINFO_HTTP_CODE);
			if($httpCode == 404) {
				trigger_error("Ooops! Page not found! Check URL", E_USER_ERROR);
			}

			curl_close($session);

			if ($response === FALSE) {
				throw new Exception(curl_error($session));
			} else {
				return $response;
			}
		} catch(Exception $e) {
			trigger_error($e->getMessage(), E_USER_ERROR);
		}
	}

	public function get_todo_lists() {
		$this->uri = '/projects/'.$this->project_id.'/todo_lists.xml';
		$curl_opts = array(CURLOPT_HTTPGET => true);
		$raw_xml = $this->connect($curl_opts);
		$xml = simplexml_load_string($raw_xml);

		$todo_lists = array();
		foreach ($xml->xpath('todo-list') as $todo_list) {
			$todo_lists[] = (string) $todo_list->id;
		}

		return $todo_lists;
	}

	public function get_todo_list_items($list_id) {
		$this->uri = '/todo_lists/'.$list_id.'/todo_items.xml';
		$curl_opts = array(CURLOPT_HTTPGET => true);
		$raw_xml = $this->connect($curl_opts);
		$xml = simplexml_load_string($raw_xml);

		$list_items = array();
		foreach ($xml->xpath('todo-item') as $todo_list) {
			$item_id = (string) $todo_list->id;
			$item_content = (string) $todo_list->content;
			$list_items[$item_id] = array(
				'list_id' => $list_id,
				'content' => $item_content
			);
		}

		return $list_items;
	}

	public function create_message() {
		$this->uri = '/projects/'.$this->project_id.'/posts.xml';
		$request_xml = "<request><post><title>Tracking ({$this->project_id})</title><body></body><private>1</private></post></request>";

		$curl_opts = array(
			CURLOPT_POSTFIELDS => $request_xml,
			CURLOPT_HEADER => true
		);
		$response = $this->connect($curl_opts);

		preg_match('@Location: /posts/(?<id>\d*)\.xml@', $response, $matches);
		return $matches['id'];
	}

	public function create_post_comment($post_id, $message) {
		$this->uri = '/posts/'.$post_id.'/comments.xml';
		$request_xml = "<comment><body>$message</body></comment>";

		$curl_opts = array(
			CURLOPT_POSTFIELDS => $request_xml,
			CURLOPT_HEADER => true
		);
		$response = $this->connect($curl_opts);

		preg_match('@Location: /comments/(?<id>\d*)\.xml@', $response, $matches);
		return $matches['id'];
	}

	/**
	 * Get all project to do lists with their items
	 *
	 * @return array
	 */
	public function get_project_data() {
		$lists = $this->get_todo_lists();
		$items = array();
		foreach ($lists as $list_id) {
			$items += $this->get_todo_list_items($list_id);
		}

		return array($lists, $items);
	}

	/**
	 * Compare local project data with remote. Detecting how many items were (added/modified/deleted)
	 */
	public function compare() {
		list(, $remote_items) = $this->get_project_data();
		list(, $local_items) = $this->db->get_project_data($this->project_id);

		$item_db = new Item();

		$counter = array(
			'added' => 0,
			'modified' => 0,
			'deleted' => 0
		);

		foreach($remote_items as $item_id => $item) {
			if(!isset($local_items[$item_id])) {
				$counter['added']++;
				$post_id = $this->create_message();
				$item_db->add($this->project_id, $item_id, $item['list_id'], $post_id, $item['content']);
				$this->create_post_comment($post_id, 'To Do item has been created!');
			}
		}

		foreach($local_items as $item_id => $item) {
			if(!isset($remote_items[$item_id])) {
				if ($item['post_id'] != 0) { //commenting only tracked to do items
					$counter['deleted']++;
					$this->create_post_comment($item['post_id'], 'To Do item has been deleted!');
				}
				$item_db->delete($item_id); //sync info in DB for both (tracked and non-tracked) items
			} elseif ($item['content'] != $remote_items[$item_id]['content']) {
				if ($item['post_id'] != 0) { //commenting only tracked to do items
					$counter['modified']++;
					$this->create_post_comment($item['post_id'], "To Do item content has been modified from: '{$item['content']}'  to: '{$remote_items[$item_id]['content']}'");
				}
				$item_db->update($item_id, $remote_items[$item_id]['content']); //sync info in DB for both (tracked and non-tracked) items
			}
		}

		echo "Created: {$counter['added']} items<br/>";
		echo "Modified: {$counter['modified']} items<br/>";
		echo "Deleted: {$counter['deleted']} items<br/>";
	}
}

class DB {
	private $url = 'localhost';
	private $username = '<set_username>';
	private $password = '<set_password>';
	private $db_name = 'wsm_bc';
	public $db;

	public function __construct() {
		$this->db = mysqli_connect($this->url, $this->username, $this->password, $this->db_name);
	}

	protected  function query($query_str) {
		if(empty($query_str)) {
			trigger_error("Empty query!", E_USER_ERROR);
		}

		$res = $this->db->query($query_str);

		if($res === false){
			var_dump($query_str);
			trigger_error("Query failed during execution!", E_USER_ERROR);
		}

		return $res;
	}

	protected function res2array(mysqli_result $res)
	{
		if($res === false) {
			return false;
		}

		$res_arr = array();
		if ($res->num_rows > 0) {
			while ($row = $res->fetch_assoc()) {
				$res_arr[] = $row;
			}
		}

		return $res_arr;
	}

	/**
	 * Synchronize local DB project data with remote
	 *
	 * @param int $project_id
	 * @param array $lists
	 * @param array $items
	 * @return bool : transaction state
	 */
	public function sync_project($project_id, $lists, $items) {
		$list_vals = array();
		$item_vals = array();

		foreach($lists as $list_id) {
			$list_vals[] = "($list_id, $project_id)";
		}

		foreach ($items as $item_id => $item) {
			$item_vals[] = "($item_id, {$item['list_id']}, 0, '{$item['content']}')";
		}

		$list_vals = implode(',', $list_vals);
		$item_vals = implode(',', $item_vals);

		$this->db->autocommit(false);
		$this->query("DELETE FROM todo_lists");
		$this->query("INSERT INTO todo_lists (id, project_id) VALUES $list_vals ON DUPLICATE KEY UPDATE id=id");
		$this->query("INSERT INTO todo_list_items (id, list_id, post_id, content) VALUES $item_vals ON DUPLICATE KEY UPDATE id=id");
		$result = $this->db->commit();
		$this->db->autocommit(true);

		return $result;
	}

	/**
	 * Get from local DB all project to do lists with their items
	 *
	 * @param int $project_id
	 * @return array
	 */
	public function get_project_data($project_id) {
		$res = $this->query("SELECT tli.* FROM todo_list_items tli JOIN todo_lists tl ON tl.id = tli.list_id AND tl.project_id = $project_id");
		$lists_items = $this->res2array($res);

		$lists = array();
		$items = array();
		foreach($lists_items as $item) {
			$lists[] = $item['list_id'];
			$items[$item['id']] = array(
				'list_id' => $item['list_id'],
				'post_id' => $item['post_id'],
				'content' => $item['content']
			);
		}

		return array($lists, $items);
	}
}

class Item extends DB {
	function add($project_id, $item_id, $list_id, $post_id, $content) {
		$this->query("INSERT INTO todo_lists (id, project_id) VALUES ($list_id, $project_id) ON DUPLICATE KEY UPDATE id=id");
		return $this->query("INSERT INTO todo_list_items (id, list_id, post_id, content) VALUES ($item_id, $list_id, $post_id, '$content')");
	}

	function update($item_id, $content) {
		return $this->query("UPDATE todo_list_items SET content = '$content' WHERE id = $item_id");
	}

	function delete($item_id) {
		return $this->query("DELETE FROM todo_list_items WHERE id = $item_id");
	}
}