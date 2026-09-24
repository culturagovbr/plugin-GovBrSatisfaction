<?php

use function MapasCulturais\__column_exists;
use function MapasCulturais\__table_exists;
use function MapasCulturais\__exec;

/**
 * Atualizações de banco do plugin.
 *
 * A chave leva o nome da tabela: App::_dbUpdates() junta os arquivos com `+=`,
 * e chaves repetidas entre plugins descartariam uma em silêncio.
 *
 * `__exec` e não `__try`, que engole a exceção: o core só deixa de marcar o
 * update como aplicado quando a closure lança, então uma falha silenciosa
 * deixaria a tabela por criar para sempre.
 */
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

        // A regra é uma avaliação por serviço, por usuário, para sempre. O índice
        // único é o que garante isso sob concorrência — duas publicações
        // simultâneas não criam dois registros.
        __exec("CREATE UNIQUE INDEX UNQ_govbr_satisfaction_request__user_servico
            ON govbr_satisfaction_request (user_id, servico)");

        // O job varre pendentes; o painel filtra por situação e por ambiente.
        __exec("CREATE INDEX IDX_govbr_satisfaction_request__subsite
            ON govbr_satisfaction_request (subsite_id)");
        __exec("CREATE INDEX IDX_govbr_satisfaction_request__send_status
            ON govbr_satisfaction_request (send_status)");
        __exec("CREATE INDEX IDX_govbr_satisfaction_request__create_timestamp
            ON govbr_satisfaction_request (create_timestamp)");
    },

    // Entrada própria, e não acrescentada à criação: instalações que já rodaram
    // a primeira têm a tabela sem esta coluna, e o guarda de __table_exists
    // faria aquele bloco inteiro ser pulado.
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

    // O corpo inteiro, sem truncar. `send_detail` continua existindo como o
    // resumo que a tabela do painel mostra — derivar isso a cada listagem
    // obrigaria a trazer o corpo completo de 25 linhas só para exibir uma frase.
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
];
