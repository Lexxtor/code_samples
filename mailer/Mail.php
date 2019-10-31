<?php

namespace app\models;

use app\components\Sendmail;
use Curl\Curl;
use Exception;
use Yii;
use yii\base\ErrorException;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "mail".
 * Модель письма из очереди.
 *
 * @property integer $id
 * @property integer $sendout_id
 * @property integer $subscriber_id
 * @property string $status          'awaits','sending',
 *                                   'delayed'    если рассылка временно невозможна
 *                                   'cancelled', если временно невозможную рассылку перевели в черновики
 *                                                     или рассыка удалена || подписчик удален
 *                                   'sended','error','delivered','opened','clicked'
 *                                   Эти статусы означают что письмо привело к действию:
 *                                   'subscribed',
 *                                   'unsubscribed',
 *                                   'registered',
 *                                   'paid'
 * @property integer $is_invite
 * @property integer $priority
 * @property integer $hour_from
 * @property integer $hour_to
 * @property string $date_scheduled
 * @property string $date_created
 * @property string $date_altered
 * @property string $date_error
 * @property string $date_sended
 * @property string $date_delivered
 * @property string $date_opened
 * @property string $date_clicked
 * @property string $date_subscribed
 * @property string $date_unsubscribed
 * @property string $date_registered
 * @property string $date_paid
 * @property string $error
 *
 * virtual:
 * @property int $paid_value
 */
class Mail extends ActiveRecord
{
    public $paid_value;
    public $toBeCanceled = false;

    const STATUS_AWAITS = 'awaits';
    const STATUS_SENDING = 'sending';
    const STATUS_DELAYED = 'delayed';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_SENDED = 'sended';
    const STATUS_ERROR = 'error';
    const STATUS_DELIVERED = 'delivered';
    const STATUS_OPENED = 'opened';
    const STATUS_CLICKED = 'clicked';
    const STATUS_SUBSCRIBED = 'subscribed';
    const STATUS_UNSUBSCRIBED = 'unsubscribed';
    const STATUS_REGISTERED = 'registered';
    const STATUS_PAID = 'paid';

    // события по которым разрешено получать статистику
    // в порядке увеличения
    public static $events_stats_allowed = [
        'error',
        'sended',
        'delivered',
        'opened',
        'clicked',
        'subscribed',
        'unsubscribed',
        'registered',
        'paid'
    ];

    private static $sendouts;
    private static $templates;
    private static $subscribers;

    private static $_statistic = []; // кэш результатов Mail::getStatistic()
    private static $_statisticQueryResult = []; // кэш внутренних результатов Mail::getStatistic()

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'mail';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['sendout_id', 'subscriber_id'], 'required'],
            [['sendout_id', 'subscriber_id', 'is_invite', 'priority', 'hour_from', 'hour_to'], 'integer'],
            [['status', 'error'], 'string'],
            [['date_scheduled'], 'safe'],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'sendout_id' => 'ID рассылки',
            'subscriber_id' => 'ID подписчика',
            'status' => 'Статус',
            'is_invite' => 'Is Invite',
            'priority' => 'приоритет',
            'hour_from' => 'Hour From',
            'hour_to' => 'Hour To',
            'date_scheduled' => 'Когда отправить',
            'date_created' => 'Date Created',
            'date_altered' => 'Date Altered',
        ];
    }

    public static function getStatusLabels()
    {
        return [
            //'' => 'пусто',
            static::STATUS_AWAITS => 'Ожидает',
            static::STATUS_SENDING => 'Отправляется',
            static::STATUS_DELAYED => 'Задержано',
            static::STATUS_CANCELLED => 'Отменено',
            static::STATUS_SENDED => 'Отправлено',
            static::STATUS_ERROR => 'Ошибка',
            static::STATUS_DELIVERED => 'Доставлено',
            static::STATUS_OPENED => 'Открыто',
            static::STATUS_CLICKED => 'Кликнуто',
            static::STATUS_SUBSCRIBED => 'Подписалось',
            static::STATUS_UNSUBSCRIBED => 'Отписалось',
            static::STATUS_REGISTERED => 'Зарегистрировалось',
            static::STATUS_PAID => 'Оплатило',
        ];
    }

    public function beforeSave($insert)
    {
        $now = date('Y-m-d H:i:s');

        if ($this->isNewRecord) {
            $this->date_created = $now;
        } else {
            if ($this->getDirtyAttributes(['status']))
                $this->date_altered = $now; // дата смены статуса

            if ($this->isStatusIncreased()) {
                if ($this->status == static::STATUS_SENDED)
                    $this->date_sended = $now;
                elseif ($this->status == static::STATUS_DELIVERED)
                    $this->date_delivered = $now;
                elseif ($this->status == static::STATUS_OPENED)
                    $this->date_opened = $now;
                elseif ($this->status == static::STATUS_CLICKED)
                    $this->date_clicked = $now;
                elseif ($this->status == static::STATUS_ERROR)
                    $this->date_error = $now;
                elseif ($this->status == static::STATUS_SUBSCRIBED)
                    $this->date_subscribed = $now;
                elseif ($this->status == static::STATUS_UNSUBSCRIBED)
                    $this->date_unsubscribed = $now;
                elseif ($this->status == static::STATUS_REGISTERED)
                    $this->date_registered = $now;
                elseif ($this->status == static::STATUS_PAID)
                    $this->date_paid = $now;
            }
        }

        // сохранить событие только если статус увеличился  // paid может прийти несколько раз
        if ($this->isStatusIncreased() || $this->status == static::STATUS_PAID) {
            EventSummary::add($this->sendout_id, $this->status, $now, $this->paid_value);
        }

        return parent::beforeSave($insert);
    }

    /**
     * Повысился ли статус. Статус должен меняться только в одну сторону.
     * @return bool
     */
    public function isStatusIncreased()
    {
        $oldStatus = $this->getOldAttribute('status');

        // если старый статус был вне массива статусов для статистики, а новый в нем
        if (array_search($this->status, static::$events_stats_allowed) !== false && array_search($oldStatus, static::$events_stats_allowed) === false)
            return true;

        return array_search($oldStatus, static::$events_stats_allowed) < array_search($this->status, static::$events_stats_allowed);
    }

    /**
     * Отсылает порцию писем.
     * @param null|int $limit размер порции, если ен указан, то берется из настройек.
     * @return int|null кол-во отосланных или null если рассылка отключена глобально
     * @throws \yii\db\Exception
     */
    public static function sendPortion($limit = null)
    {
        if (@Yii::$app->params['mailer_disabled'])
            return null;

        if (!$limit)
            $limit = isset(Yii::$app->params['sending_portion']) ? Yii::$app->params['sending_portion'] : 10;

        // транзакционно берем порцию и даем письмам статус sending
        $transaction = Yii::$app->db->beginTransaction(); // todo: optimize: вместо дефолтной Transaction::REPEATABLE_READ передавать Transaction::READ_COMMITTED, чтобы не блокировалась вставка новых писем. !! Разобраться что за ошибка происходит при Transaction::READ_COMMITTED: SQLSTATE[HY000]: General error: 1665 Cannot execute statement: impossible to write to binary log since BINLOG_FORMAT = STATEMENT and at least one table uses a storage engine limited to row-based logging. InnoDB is limited to row-logging when transaction isolation level is READ COMMITTED or READ UNCOMMITTED
        $mails = static::findBySql("SELECT * FROM mail
            WHERE status='awaits'
                AND (date_scheduled IS NULL OR date_scheduled <= NOW())
                AND (hour_from IS NULL OR hour_from <= HOUR(NOW()))
                AND (hour_to IS NULL OR hour_to >= HOUR(NOW()))
            ORDER BY priority DESC LIMIT $limit FOR UPDATE")->all();

        if ($mails) {
            // обновляем статусы писем
            $ids = [];
            foreach ($mails as $mail) {
                /* @var static $mail */
                $ids[] = $mail->id;
            }
            $ids_imploded = implode(',', $ids);
            $affected = Yii::$app->db->createCommand("UPDATE mail SET status='sending', date_altered=NOW() WHERE id IN ($ids_imploded)")->execute();
        } else {
            $affected = 0;
        }

        $transaction->commit();

        if (!$mails) return 0;

        // рассылаем и меняем статус на sended или error, если не отослалось.
        $sended = 0;
        foreach ($mails as $mail) {
            /* @var static $mail */
            $sended += $mail->send();
        }

        if ($sended != $affected) {
            Yii::error('Some mails not sended.', 'mailer');
        }

        return $sended;
    }

    /**
     * Отправляет это письмо из очереди. Меняет его статус на "отослано" или другой (если не отослано).
     * @return boolean|null whether this message is sent successfully or null if mailing is disabled globally.
     * @throws \yii\console\Exception
     * @throws \yii\web\HttpException
     */
    public function send()
    {
        if (@Yii::$app->params['mailer_disabled'])
            return null;

        Yii::trace("Mail {$this->id} starting send().", 'mailer');

        // берем необходимые данные и проверяем их наличие
        $sendout = $this->getSendout(false);
        if (!$sendout) {
            Yii::error("Mail {$this->id} cancelled: sendout not found.", 'mailer');
            $this->saveStatus(static::STATUS_CANCELLED);
            return false;
        }

        $subscriber = $this->getSubscriber();
        if (!$subscriber) {
            Yii::error("Mail {$this->id} cancelled: subscriber not found.", 'mailer');
            $this->saveStatus(static::STATUS_CANCELLED);
            return false;
        }

        $template = $this->getTemplate();
        if (!$template) {
            Yii::error("Mail {$this->id} sendout {$sendout->id}, template {$this->sendout_id} not found.", 'mailer');
            $this->saveStatus(static::STATUS_DELAYED);
            $sendout->pause("Шаблон рассылки не найден.");
            return false;
        }

        $domain = $sendout->getProperDomain(false, $ipErrorMessage);
        if (!$domain) {
            if ($ipErrorMessage) {
                // не найден годный IP, письмо откладываем на отправку попозже
                Yii::info("Proper IP not found, decreasing mail {$this->id} priority: " . $ipErrorMessage, 'mailer');
                $this->decreasePriority(static::STATUS_AWAITS);
                return false;
            }

            $this->saveStatus(static::STATUS_DELAYED);
            $sendout->pause("Подходящий домен не найден.");
            Yii::error("Mail delayed, sendout paused: proper domain for sendout #{$this->sendout_id} not found.", 'mailer');
            return false;
        }

        Yii::trace("Mail {$this->id} data exist and validated.", 'mailer');

        try {
            // компонуем письмо, рендерим шаблон
            $sender = new Sendmail([
                'domain' => $domain,
                'template' => $template,
                'subscriber' => $subscriber,
                'sendout' => $sendout,
                'mail' => $this,
                'from' => $sendout->from_mail,
                'subject' => $this->is_invite ? $sendout->invite_subject : $sendout->subject,
                'email' => @Yii::$app->params['redirect_to_test_subscriber'] ? Subscriber::getTestSubscriber()->email : null,
            ]);

            Yii::trace("Mail {$this->id} composed, ready to send.", 'mailer');

            if ($this->toBeCanceled) { // при рендере шаблона письмо может быть отменено
                $this->saveStatus(static::STATUS_CANCELLED);
                Yii::trace("Mail {$this->id} cancelled die to \$toBeCanceled flag.", 'mailer');
                return false;
            }

            // логируем письмо
            if (@Yii::$app->params['log_all_outgoing_mails']) {
                Yii::trace("Mail:\n\n" . $sender->getMessageAsString(), 'mail');
            }

            // отправляем письмо
            try {
                $isSended = $sender->send();
            } catch (Exception $e) {
                Yii::trace("Mail {$this->id} error {$e->getMessage()}", 'mailer');
                if ($this->saveStatus(static::STATUS_AWAITS)) {
                    Yii::trace("Mail {$this->id} status -> awaits.", 'mailer');
                }
                else {
                    Yii::trace("Mail {$this->id} unable to change status.", 'mailer');
                }

                return false;
            }

            if ($isSended) {
                $this->status = static::STATUS_SENDED;
                Yii::trace("Mail {$this->id} sended.", 'mailer');
            } else {
                $this->status = static::STATUS_ERROR;
                Yii::trace("Mail {$this->id} failed to send.", 'mailer');
            }

            if (!$this->save()) {
                throw new \yii\base\Exception('Could not save: ' . implode(', ', $this->firstErrors));
            }
        } catch (\Exception $e) {
            Yii::trace("Mail {$this->id} failed to send: " . $e->getMessage(), 'mailer');
            $this->status = static::STATUS_ERROR;
            $this->error = $e->getMessage();
            if (!$this->save()) {
                throw new \yii\base\Exception('Could not save: ' . implode(', ', $this->firstErrors));
            }
            $isSended = false;
        }

        if ($isSended) $this->ping();

        return $isSended;
    }

    /**
     * Выдает Рассылку от этого письма.
     * @param bool $throwException
     * @return Sendout|null
     * @throws ErrorException если рассылка не найдена
     */
    public function getSendout($throwException = true)
    {
        if (isset(static::$sendouts[$this->sendout_id]))
            return static::$sendouts[$this->sendout_id];

        $sendout = Sendout::findOne($this->sendout_id);

        if (!$sendout && $throwException)
            throw new ErrorException("Sendout not found for mail #$this->id");

        static::$sendouts[$this->sendout_id] = $sendout;

        return $sendout;
    }

    /**
     * Выдает Рассылку от этого письма.
     * @return null|Template
     */
    public function getTemplate()
    {
        if ($this->is_invite)
            $template_id = $this->getSendout()->template_invite_id;
        else
            $template_id = $this->getSendout()->template_id;

        if (isset(static::$templates[$template_id]))
            return static::$templates[$template_id];

        return static::$templates[$this->sendout_id] = Template::findOne($template_id);
    }

    /**
     * @return null|Subscriber
     */
    public function getSubscriber()
    {
        return Subscriber::find()
            ->select(null, 'SQL_NO_CACHE')
            ->where(['id' => $this->subscriber_id])
            ->one();
    }

    public static function countByStatus($sendout_id = null)
    {
        return static::find()
            ->select('status, count(*) AS number')
            ->filterWhere(['sendout_id' => $sendout_id])
            ->groupBy('status')
            ->asArray()
            ->all();
    }

    /**
     * Делает GET запрос на ping_url рассылки, если есть.
     */
    public function ping()
    {
        $ping_url = str_replace([
            '{mail_id}',
            '{sendout_id}',
            '{subscriber_id}',
        ], [
            $this->id,
            $this->sendout_id,
            $this->subscriber_id,
        ], $this->getSendout()->ping_url);

        if (!$ping_url) return;

        // todo: CURLOPT_NOSIGNAL чтобы не ждать ответа?
        $curl = new Curl;
        $curl->setUserAgent('MailerPing');

        $curl->get($ping_url);
    }

    /**
     * Сохраняет письмо с новым статусом и параметрами, если они заданы.
     * @param $status
     * @param array $data extra данные, например ['paid_value'=>сумма платежа]
     * @return bool success or not
     */
    public function saveStatus($status, $data = [])
    {
        Yii::configure($this, $data);
        $this->status = $status;
        return $this->save(false);
    }

    public function decreasePriority($status = null, $score = -1)
    {
        if ($status)
            $this->status = $status;

        $this->priority += $score;

        return $this->save(false);
    }

    /**
     * Дает статистику с датами js timestamp
     * @param null $event
     * @param int|null $sendout_id
     * @param int|string $from дата (Y-m-d H:i:s) или кол-во дней, напр 7 - статистика за неделю.
     * @param string|null $to дата (Y-m-d H:i:s)
     * @param string $timeFormat if not 'js' then php timestamp
     * @return array
     * @throws ErrorException
     */
    public static function getStatistic($event, $sendout_id = null, $from, $to = null, $timeFormat = 'js')
    {
        // validation
        if (!in_array($event, static::$events_stats_allowed))
            throw new ErrorException('Param $event not in Mail::$events_stats_allowed');

        // convert
        if (!strtotime($from))
            $from = date('Y-m-d H:i:s', strtotime('-' . $from . ' days'));
        else
            $from = date('Y-m-d H:i:s', strtotime($from));

        if (!strtotime($to))
            $to = date('Y-m-d 23:59:59', strtotime('now'));
        else
            $to .= ' 23:59:59';

        // static cache
        $staticKey = $event . 'f' . $from . 't' . $to . 's' . $sendout_id;
        if (!isset(static::$_statistic[$staticKey])) {
            $where = '';
            $params = [];
            if ($sendout_id) {
                $where .= ' AND sendout_id = :sendout_id';
                $params[':sendout_id'] = $sendout_id;
            }
            if ($from) {
                $where .= ' AND (date_created >= :from OR date_altered >= :from)';
                $params[':from'] = $from;
            }
            if ($to) {
                $where .= ' AND date_created <= :to';
                $params[':to'] = $to;
            }

            if (!isset(static::$_statisticQueryResult[implode(',', $params)])) {
                $mails = Yii::$app->db
                    ->createCommand('SELECT * FROM ' . static::tableName() . ' WHERE status NOT IN ("awaits","sending") ' . $where, $params)
                    ->queryAll();

                static::$_statisticQueryResult[implode(',', $params)] = $mails;
            } else
                $mails = static::$_statisticQueryResult[implode(',', $params)];

            // grouping by event type
            $tmpResults = [];
            foreach ($mails as $mail) {
                if ($mail['date_' . $event]) {
                    $date = strtotime(substr($mail['date_' . $event], 0, 10));
                    @$tmpResults[$date]++;
                }
            }
            ksort($tmpResults);

            static::$_statistic[$staticKey] = $tmpResults;
        }

        // period to fill
        $period = Variable::getDatesPeriod($from, $to ? $to : 'now', $timeFormat);

        $results = [];
        foreach ($period as $date) {
            if ($timeFormat == 'js')
                $findDate = $date / 1000;
            else
                $findDate = $date;

            $results[] = [
                $date,
                @static::$_statistic[$staticKey][$findDate] ? @static::$_statistic[$staticKey][$findDate] : 0,
            ];
        }

        return $results;

//        return [
//            [strtotime('2016-01-13') * 1000, rand(0,10)],
//            [strtotime('2016-01-14') * 1000, rand(0,20)],
//            [strtotime('2016-01-15') * 1000, rand(0,20)],
//        ];
    }

    /**
     * Статистика по нескольким событиям, для таблицы GridView
     * @param $events
     * @param null $sendout_id
     * @param $from
     * @param null $to
     * @param string $timeFormat
     * @return array
     * @throws ErrorException
     */
    public static function getStatisticTable($events, $sendout_id = null, $from, $to = null, $timeFormat = 'js')
    {
        $result = [];
        foreach ($events as $event) {
            $stats = static::getStatistic($event, $sendout_id, $from, $to, $timeFormat);
            foreach ($stats as $stat) {
                if (!isset($result[$stat[0]]))
                    $result[$stat[0]] = [
                        'date' => $stat[0], $event => $stat[1]
                    ];
                else
                    $result[$stat[0]] += [
                        'date' => $stat[0], $event => $stat[1]
                    ];
            }
        }
        return array_values($result);
    }

    /**
     * Отменяет посылку письма
     * @param bool $cancel
     * @return string
     */
    public function cancel($cancel = true)
    {
        $this->toBeCanceled = $cancel;
        if ($cancel)
            return 'Mail will be cancelled.';
    }
}