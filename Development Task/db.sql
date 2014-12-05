CREATE DATABASE IF NOT EXISTS `wsm_bc` DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci;
USE `wsm_bc`;

-- --------------------------------------------------------


CREATE TABLE IF NOT EXISTS `todo_list_items` (
`id` int(11) NOT NULL,
  `list_id` int(11) NOT NULL,
  `content` text NOT NULL,
  `post_id` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `list_id` (`list_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `todo_lists` (
`project_id` int(11) NOT NULL,
  `id` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


ALTER TABLE `todo_list_items`
  ADD CONSTRAINT `todo_list_items_ibfk_1` FOREIGN KEY (`list_id`) REFERENCES `todo_lists` (`id`) ON DELETE CASCADE;