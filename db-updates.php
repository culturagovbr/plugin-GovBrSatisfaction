<?php

use function MapasCulturais\__column_exists;
use function MapasCulturais\__table_exists;
use function MapasCulturais\__exec;

return [
    'create govbr_satisfaction_request table' => function () {
        if (__table_exists('govbr_satisfaction_request')) {
            return;
        }

        __exec("CREATE SEQUENCE govbr_satisfaction_request_id_seq INCREMENT BY 1 MINVALUE 1 START 1");

        __exec("CREATE TABLE govbr_satisfaction_request (
            id INT NOT NULL DEFAULT nextval('govbr_satisfaction_request_id_seq'),
            user_id INT NOT NULL,
            servico VARCHAR(32) NOT NULL,
            subsite_id INT NULL,
            object_type VARCHAR(255) NULL,
            object_id INT NULL,
            send_status VARCHAR(32) NOT NULL,
            etapa VARCHAR(64) NOT NULL,
            data_etapa TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            situacao_etapa VARCHAR(8) NOT NULL,
            canal_prestacao VARCHAR(8) NOT NULL,
            canal_avaliacao VARCHAR(8) NOT NULL,
            orgao VARCHAR(32) NULL,
            ip_origem VARCHAR(45) NULL,
            ip_usuario VARCHAR(45) NULL,
            create_timestamp TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            send_timestamp TIMESTAMP(0) WITHOUT TIME ZONE NULL,
            PRIMARY KEY(id)
        )");

        __exec("ALTER TABLE govbr_satisfaction_request
            ADD CONSTRAINT FK_govbr_satisfaction_request_user
            FOREIGN KEY (user_id) REFERENCES usr (id) ON DELETE CASCADE");

        __exec("ALTER TABLE govbr_satisfaction_request
            ADD CONSTRAINT FK_govbr_satisfaction_request_subsite
            FOREIGN KEY (subsite_id) REFERENCES subsite (id) ON DELETE SET NULL");

        __exec("CREATE UNIQUE INDEX UNQ_govbr_satisfaction_request__user_servico
            ON govbr_satisfaction_request (user_id, servico)");

        __exec("CREATE INDEX IDX_govbr_satisfaction_request__subsite
            ON govbr_satisfaction_request (subsite_id)");
        __exec("CREATE INDEX IDX_govbr_satisfaction_request__send_status
            ON govbr_satisfaction_request (send_status)");
        __exec("CREATE INDEX IDX_govbr_satisfaction_request__create_timestamp
            ON govbr_satisfaction_request (create_timestamp)");
    },

    'add send_attempts to govbr_satisfaction_request' => function () {
        if (__column_exists('govbr_satisfaction_request', 'send_attempts')) {
            return;
        }

        __exec("ALTER TABLE govbr_satisfaction_request
            ADD COLUMN send_attempts SMALLINT NOT NULL DEFAULT 0");
    },

    'add send response columns to govbr_satisfaction_request' => function () {
        if (__column_exists('govbr_satisfaction_request', 'send_detail')) {
            return;
        }

        __exec("ALTER TABLE govbr_satisfaction_request
            ADD COLUMN send_http_status SMALLINT NULL,
            ADD COLUMN send_detail VARCHAR(500) NULL");
    },

    'add send_response to govbr_satisfaction_request' => function () {
        if (__column_exists('govbr_satisfaction_request', 'send_response')) {
            return;
        }

        __exec("ALTER TABLE govbr_satisfaction_request
            ADD COLUMN send_response TEXT NULL");
    },

    'add send_payload to govbr_satisfaction_request' => function () {
        if (__column_exists('govbr_satisfaction_request', 'send_payload')) {
            return;
        }

        __exec("ALTER TABLE govbr_satisfaction_request
            ADD COLUMN send_payload TEXT NULL");
    },

    'create govbr_satisfaction_dispatch table' => function () {
        if (__table_exists('govbr_satisfaction_dispatch')) {
            return;
        }

        __exec("CREATE SEQUENCE govbr_satisfaction_dispatch_id_seq INCREMENT BY 1 MINVALUE 1 START 1");

        __exec("CREATE TABLE govbr_satisfaction_dispatch (
            id INT NOT NULL DEFAULT nextval('govbr_satisfaction_dispatch_id_seq'),
            request_id INT NOT NULL,
            uuid VARCHAR(36) NOT NULL,
            origin VARCHAR(16) NOT NULL,
            state VARCHAR(16) NOT NULL,
            user_id INT NULL,
            create_timestamp TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            finish_timestamp TIMESTAMP(0) WITHOUT TIME ZONE NULL,
            PRIMARY KEY(id)
        )");

        __exec("ALTER TABLE govbr_satisfaction_dispatch
            ADD CONSTRAINT FK_govbr_satisfaction_dispatch_request
            FOREIGN KEY (request_id) REFERENCES govbr_satisfaction_request (id) ON DELETE CASCADE");

        __exec("ALTER TABLE govbr_satisfaction_dispatch
            ADD CONSTRAINT FK_govbr_satisfaction_dispatch_user
            FOREIGN KEY (user_id) REFERENCES usr (id) ON DELETE SET NULL");

        __exec("CREATE UNIQUE INDEX UNQ_govbr_satisfaction_dispatch__uuid
            ON govbr_satisfaction_dispatch (uuid)");
        __exec("CREATE INDEX IDX_govbr_satisfaction_dispatch__request_created
            ON govbr_satisfaction_dispatch (request_id, create_timestamp DESC)");
        __exec("CREATE INDEX IDX_govbr_satisfaction_dispatch__user
            ON govbr_satisfaction_dispatch (user_id)");
    },

    'create govbr_satisfaction_attempt table' => function () {
        if (__table_exists('govbr_satisfaction_attempt')) {
            return;
        }

        __exec("CREATE SEQUENCE govbr_satisfaction_attempt_id_seq INCREMENT BY 1 MINVALUE 1 START 1");

        __exec("CREATE TABLE govbr_satisfaction_attempt (
            id INT NOT NULL DEFAULT nextval('govbr_satisfaction_attempt_id_seq'),
            dispatch_id INT NOT NULL,
            number SMALLINT NOT NULL,
            max_attempts SMALLINT NOT NULL,
            outcome VARCHAR(16) NOT NULL,
            method VARCHAR(8) NULL,
            endpoint TEXT NULL,
            http_status SMALLINT NULL,
            payload TEXT NULL,
            response TEXT NULL,
            response_truncated BOOLEAN NOT NULL DEFAULT FALSE,
            response_headers TEXT NULL,
            detail VARCHAR(500) NULL,
            sent_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            duration_ms INT NULL,
            PRIMARY KEY(id)
        )");

        __exec("ALTER TABLE govbr_satisfaction_attempt
            ADD CONSTRAINT FK_govbr_satisfaction_attempt_dispatch
            FOREIGN KEY (dispatch_id) REFERENCES govbr_satisfaction_dispatch (id) ON DELETE CASCADE");

        __exec("CREATE INDEX IDX_govbr_satisfaction_attempt__dispatch
            ON govbr_satisfaction_attempt (dispatch_id, number)");
    },
];
