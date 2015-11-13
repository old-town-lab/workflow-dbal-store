CREATE TABLE `os_property_entry`
(
    `global_key` VARCHAR(250) NOT NULL,
    `item_key` VARCHAR(250) NOT NULL,
    `item_type` TINYINT,
    `string_value` VARCHAR(255),
    `date_value` DATETIME,
    `data_value` BLOB,
    `float_value` FLOAT,
    `number_value` NUMERIC,
    PRIMARY KEY (`global_key`, `item_key`)
);

CREATE TABLE `os_wf_entry`
(
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(60),
    `state` INTEGER,
    PRIMARY KEY (`id`)
);


CREATE TABLE `os_current_step`
(
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `entry_id` BIGINT,
    `step_id` INTEGER,
    `action_id` INTEGER,
    `owner` VARCHAR(35),
    `start_date` DATETIME,
    `finish_date` DATETIME,
    `due_date` DATETIME,
    `status` VARCHAR(40),
    `caller` VARCHAR(35),

    PRIMARY KEY (`id`),
    INDEX (`entry_id`),
    FOREIGN KEY (`entry_id`) REFERENCES os_wf_entry(`id`)
);

CREATE TABLE `os_history_step`
(
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `entry_id` BIGINT,
    `step_id` INTEGER,
    `action_id` INTEGER,
    `owner` VARCHAR(35),
    `start_date` DATETIME,
    `finish_date` DATETIME,
    `due_date` DATETIME,
    `status` VARCHAR(40),
    `caller` VARCHAR(35),

    PRIMARY KEY (`id`),
    INDEX (`entry_id`),
    FOREIGN KEY (`entry_id`) REFERENCES os_wf_entry(`id`)
);

CREATE TABLE `os_current_step_prev`
(
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `previous_id` BIGINT NOT NULL,
    PRIMARY KEY (`id`, `previous_id`),
    INDEX (`id`),
    FOREIGN KEY (`id`) REFERENCES os_current_step(`id`),
    INDEX (`previous_id`),
    FOREIGN KEY (`previous_id`) REFERENCES os_history_step(`id`)
);

CREATE TABLE `os_history_step_prev`
(
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `previous_id` BIGINT NOT NULL,
    PRIMARY KEY (`id`, `previous_id`),
    INDEX (`id`),
    FOREIGN KEY (`id`) REFERENCES os_history_step(`id`),
    INDEX (`previous_id`),
    FOREIGN KEY (`previous_id`) REFERENCES os_history_step(`id`)
);