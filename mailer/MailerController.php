<?php

namespace app\commands;

use app\models\Domain;
use app\models\IpSend;
use app\models\Mail;
use app\models\Sendout;
use app\models\Subscriber;
use app\models\Variable;
use Ddeboer\Imap\Server;
use Yii;
use yii\console\Controller;
use yii\data\ActiveDataProvider;
use yii\data\ArrayDataProvider;
use yii\db\Expression;

/**
 * Команды рассылки.
 */
class MailerController extends Controller
{
    /**
     * Выдает статистику по очереди писем. Через пробел можно указать ID рассылки или 'l' чтобы получить список рассылок.
     * @param null|int|string $param
     * @return int
     */
    public function actionIndex($param = null)
    {
        if ($param == 'l') {
            $sendouts = Sendout::find()->select('id, name, status, frequency, date_last_sendout')->asArray()->all();

            echo "id\t".Variable::getPadded($sendouts, 'name', 'name')."\tstatus\tfrequency\tdate_last_sendout\n";
            foreach ($sendouts as $sendout) {
                foreach ($sendout as $name => $value) {
                    echo Variable::getPadded($sendouts, $name, $value) . "\t";
                }
                echo "\n";
            }
        }
        else {
            foreach (Mail::countByStatus($param) as $item) {
                echo $item['status'] . "\t" . $item['number'] . "\n";
            };
        }
        echo "\n";

        return static::EXIT_CODE_NORMAL;
    }

    /**
     * Отсылает письма из очереди. Через пробел можно указать ограничение на кол-во,
     * тогда ограничение не будет превышено более чем на 1 порцию. Без ограничения отсылает все письма,
     * беря их из очереди порционно.
     * Выводит кол-во обработанных писем и знак "!", если могли остаться еще письма, например: "42!".
     * Выводит 0, если отправка отключена глобально или писем в очереди нет.
     * @param null|int $maxSend
     * @param int $rounds
     * @param int $sleep
     * @return int
     */
    public function actionSend($maxSend = null, $rounds = 9, $sleep = 5)
    {
        while ($rounds--)
        {
            $sumSended = 0;

            do {
                $sended = Mail::sendPortion();
                $sumSended += $sended;

                if (memory_get_usage(1) + 1024*500 > Variable::getPhpMemoryLimit()) {
                    Yii::warning('Dangerous memory usage peak: '.memory_get_usage(1).' bytes. Stopping mailer process.', 'mailer');
                    break;
                }
            } while ($sended && (!$maxSend || $sumSended < $maxSend));

            echo $sumSended , ($sended ? '!' : ''), "\n";

            if ($rounds) sleep($sleep);
        }

        return static::EXIT_CODE_NORMAL;
    }

    /**
     * Добавляет новые письма в очередь, в зависимости от созданных рассылок. Выводит кол-во добавленных писем. Ничего не выводит, если отправка отключена глобально.
     * @return int
     */
    public function actionSchedule()
    {
        echo Sendout::scheduleAll();
        echo "\n";

        return static::EXIT_CODE_NORMAL;
    }

    /**
     * Команда проверяет домены и по результатам меняет их статусы.
     * @param bool $onlyVerified проверить только проверенные
     * @return int
     */
    public function actionCheckDomains($onlyVerified = true)
    {
        Yii::$app->urlManager->scriptUrl = '/';

        if ($onlyVerified)
            $domains = Domain::find()->where(['status' => Domain::STATUS_VERIFIED])->all();
        else
            $domains = Domain::find()->all();

        echo "Checking ".sizeof($domains)." domains:\n";
        $bad = 0;
        foreach ($domains as $domain) {
            echo $domain->domain . "   ";
            $result = $domain->verify();
            if ($result) {
                $bad++;
                echo "\n";
                $errors = print_r($result, true);
                echo substr($errors, 8, -2);
                echo "\n";
            }
            else
                echo "OK\n\n";
        }

        echo "\n";
        if ($bad)
            echo "$bad marked as invalid.\n";
        else
            echo "All domains is valid.\n";

        return static::EXIT_CODE_NORMAL;
    }

    /**
     * Удаляет старые логи отправок для IP.
     * @param int $minutes
     * @return int
     */
    public function  actionCleanIpSendLog($minutes = 60)
    {
        $n = IpSend::deleteOld($minutes);

        echo "Records deleted: $n\n\n";

        return static::EXIT_CODE_NORMAL;
    }

    /**
     * Проверяет логи SMTP и банит емейлы вызвавшие ошибку 550 при отправке.
     * @return int
     */
    public function  actionCheckLogs()
    {
        $n = Subscriber::checkSmtpLog();

        echo "E-mails banned: $n\n\n";

        return static::EXIT_CODE_NORMAL;
    }

    /**
     * Выводит логи SMTP с ошибкой 550 при отправке.
     * @return int
     */
    public function  actionShowLogs()
    {
        $lines = Subscriber::getSmtpLogLines();

        echo "\n";
        foreach ($lines as $line) {
            echo $line."\n";
        }
        echo "\n";

        return static::EXIT_CODE_NORMAL;
    }

    public function  actionTest()
    {
        print_r(IpSend::getIpSendCount(1));

        return static::EXIT_CODE_NORMAL;
    }

    /**
     * Рассылает авто-ответы на письма пришедшие на домены рассылок.
     * @param int $limit максимум ответов за одно выполнение этой команды.
     * @return int
     */
    public function actionAnswerEmails($limit = 10)
    {
        $server = new Server('post.badanga.ru', 143, '');
        $connection = $server->authenticate('noreply@post.badanga.ru', 'jmfc7843njf');

        $mailboxes = $connection->getMailboxes();
        foreach ($mailboxes as $mailbox) {
            printf("Mailbox %s has %s messages\n", $mailbox->getName(), $mailbox->count());
        }
        $mailbox = $connection->getMailbox('INBOX');
        $messages = $mailbox->getMessages();
        $message = $mailbox->getMessage(1);

//        foreach ($messages as $message) {
//        $message = array_pop($messages);
            // $message is instance of \Ddeboer\Imap\Message
//            print_r($message->getFrom());
//            print_r($message->getDecodedContent());
            print_r($message->getContent());
//            print_r($message->getBodyText());
//        }

        return static::EXIT_CODE_NORMAL;
    }
}
