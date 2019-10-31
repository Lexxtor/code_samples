<?php

use yii\db\Migration;

class m160512_000001_mail extends Migration
{
    public function up()
    {
        // таблица отправленных/отправляемых писем
        $this->createTable('mail', [
            'id' => $this->primaryKey(),
            'sendout_id' => $this->integer()->notNull(),
            'subscriber_id' => $this->integer()->notNull(),
            'status' => "ENUM('awaits','sending','sended','error','delivered','opened','clicked') NOT NULL DEFAULT 'awaits'",
            'is_invite' => 'TINYINT(1) not null',
            'priority' => 'TINYINT(1) not null',
            'hour_from' => 'TINYINT(1)', // часы рассылки от
            'hour_to' => 'TINYINT(1)', // часы рассылки до
            'date_scheduled' => 'DATETIME NULL', // когда отправлять - для отложенных приглашений
            'date_created' => 'DATETIME',
            'date_altered' => 'DATETIME',
        ]);

        $this->createIndex('idx_status', 'mail', 'status');
        $this->createIndex('idx_date_scheduled', 'mail', 'date_scheduled');
    }

    public function down()
    {
        $this->dropTable('mail');
    }

    /*
    // Use safeUp/safeDown to run migration code within a transaction
    public function safeUp()
    {
    }

    public function safeDown()
    {
    }
    */
}
