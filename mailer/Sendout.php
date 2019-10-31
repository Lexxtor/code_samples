<?php

namespace app\models;

use Curl\Curl;
use DateTime;
use Yii;
use yii\base\Exception;
use yii\db\ActiveRecord;
use yii\db\Command;
use yii\db\Query;
use yii\helpers\ArrayHelper;
use yii\validators\EmailValidator;

/**
 * This is the model class for table "sendout".
 *
 * @property integer $id
 * @property integer $site_id
 * @property string $name
 * @property string $status  active|paused|done|draft
 * @property string $lang
 * @property string $type
 * @property integer $priority
 * @property string $ping_url
 * @property string $unsubscribe_ping_url
 * @property string|array $sources
 * @property string $from_name
 * @property string $from_mail
 * @property array  $domains_ids домены для рассылки, сохраняются в таблицу связей sendout_to_domain
 * @property string $filters
 * @property string $subject
 * @property string $send_invite 1|0
 * @property integer $invite_delay_hours через сколько часов, после попадания подписчика в БД, отправлять приглашение.
 * @property string $invite_subject
 * @property integer $template_id
 * @property integer $template_invite_id
 * @property integer $frequency частота рассылки в сутках; 0 = одноразовая рассылка, 1 = раз в сутки, 2 = раз в 2 суток и тд.
 *                              -1 = по запросу, -2 по выбранным дням недели.
 * @property string|array $week_days по каким дням недели отправлять, через запятую, 1 = понедельник, 2 = вторник, ...
 * @property integer $hours_from
 * @property integer $hours_to
 * @property string $track_views
 * @property string $track_clicks
 * @property string $x_mailru_msgtype
 * @property string $date
 * @property string $date_altered
 * @property string $date_last_sendout  // время последней рассылки
 * @property int $tested  // протестена ли
 * @property int $tested_invite  // протестено ли приглащение
 * @property string $test_emails  // textarea с емайлами для теста
 * @property string $pause_reason
 */
class Sendout extends ActiveRecord
{
    public $domains_ids; // домены для таблицы связей

    const TYPE_UNWANTED = 'unwanted';
    const TYPE_WANTED = 'wanted';
    const DEVICE_ANY = null;
    const DEVICE_DESKTOP = 'desktop';
    const DEVICE_TABLET = 'tablet';
    const DEVICE_PHONE = 'phone';
    const GENDER_ANY = 'any';
    const GENDER_MALE = 'm';
    const GENDER_FEMALE = 'f';
    const GENDER_PAIR = 'p';
    const REG_STATUS_NO = 'no';
    const REG_STATUS_1 = '1';
    const REG_STATUS_2 = '2';
    const THEMATIC_NORMAL = 'normal';
    const THEMATIC_SWING = 'swing';
    const THEMATIC_BDSM = 'bdsm';
    const THEMATIC_GAY = 'gay';
    const THEMATIC_LESBI = 'lesbi';
    const BOOL_YES = 1;
    const BOOL_NO = 0;
    const STATUS_ACTIVE = 'active';
    const STATUS_PAUSED = 'paused';
    const STATUS_DONE = 'done';
    const STATUS_DRAFT = 'draft';

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'sendout';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['name', 'type', 'lang', 'from_mail'], 'required'],
            [['sources','week_days'], 'default', 'value'=>[]],
            [['type', 'ping_url', 'unsubscribe_ping_url', 'send_invite', 'track_views', 'track_clicks','test_emails'], 'string'],
            [['invite_delay_hours', 'template_id', 'template_invite_id', 'priority', 'frequency'], 'integer'],
            [['hours_from', 'hours_to'], 'integer', 'min' => 0, 'max' => 24],
            [['hours_from'], 'compare', 'compareAttribute' => 'hours_to', 'type' => 'number', 'operator' => '<='],
            [['name', 'ping_url','unsubscribe_ping_url'], 'string', 'max' => 128],
            [['ping_url','unsubscribe_ping_url'], 'url'],
            ['lang', 'string', 'max' => 2],
            ['lang', 'in', 'range' => array_keys(static::getLanguages())],
            ['status', 'in', 'range' => array_keys(static::getStatusList())],
            [['subject', 'invite_subject'], 'string', 'max' => 65535],
            [['x_mailru_msgtype'], 'string', 'max' => 255],
            [['from_name', 'from_mail'], 'string', 'max' => 64],
            ['from_mail', 'match', 'pattern' => '/^[a-z0-9\-]+$/i', 'message' => 'Допускаются только цифры и латинские буквы.'],
            ['site_id', 'required'],
            ['site_id', 'exist', 'targetClass' => Site::className(), 'targetAttribute' => 'id'],
            // todo: site_id check owner чтобы редактировать и выбирать мог только владелец определенных сайтов
            ['type', 'in', 'range' => array_keys(static::getTypes())],
            ['sources', 'in', 'range' => array_keys(Settings::getSourcesList()), 'allowArray' => true],
            ['week_days', 'in', 'range' => array_keys(static::getWeekDaysList()), 'allowArray' => true],
            ['template_id', 'exist', 'targetClass' => Template::className(), 'targetAttribute' => 'id'],
            ['template_invite_id', 'exist', 'targetClass' => Template::className(), 'targetAttribute' => 'id'],
            ['filters', 'safe'],
            ['domains_ids', 'each', 'skipOnEmpty' => false, 'rule' => ['integer']], // todo: norm validate
//            ['domain', 'string', 'max' => 32],
//            ['domain', 'in', 'range' => array_keys(static::getDomains())],
//            ['domain', function ($attribute){
//                if (!$this->getProperDomain(false)) {
//                    if (intval($this->domain))
//                        $this->addError($attribute, 'Выбранный домен ('.$this->domain.') не существует или не проверен.');
//                    else
//                        $this->addError($attribute, 'В выбранной категории доменов ('.$this->domain.') нет проверенных.');
//                }
//            }],
            ['test_emails', function ($attribute){
                $validator = new EmailValidator();
                foreach ($this->getTestEmails() as $email)
                    if (!$validator->validate($email, $error)) $this->addError($attribute, 'Невалидный емейл: '.$email);
            }],
            ['status', function ($attribute){
                if ($this->status == static::STATUS_ACTIVE && !$this->tested)
                    $this->addError($attribute, 'Рассылка не протестирована. Нажмите кнопку "Тест рассылки" или смените статус.');

                if ($this->status == static::STATUS_ACTIVE && $this->send_invite && !$this->tested_invite)
                    $this->addError($attribute, 'Включена отправка приглашений, но приглашения не протестированы. Нажмите кнопку "Тест приглашений" или смените статус.');

                if (!in_array($this->status, [static::STATUS_ACTIVE, static::STATUS_DRAFT]))
                    $this->addError($attribute, 'Рассылку можно сохранить только со статусом "Активна" или "Черновик".');
            }],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'site_id' => 'Проект',
            'sources' => 'Действия',
            'name' => 'Название',
            'status' => 'Статус',
            'lang' => 'Язык',
            'type' => 'Тип',
            'priority' => 'Приоритет',
            'ping_url' => 'URL пинга при успешной отправке письма',
            'unsubscribe_ping_url' => 'URL пинга при отписке',
            'from_name' => 'Имя отправителя',
            'from_mail' => 'Имя e-mail`а отправителя (без домена и @)',
            'domains_ids' => 'Домены',
            'filters' => 'Filters',
            'subject' => 'Тема письма',
            'send_invite' => 'Отправлять приглашение',
            'invite_delay_hours' => 'Задержка отправки приглашения',
            'invite_subject' => 'Тема письма приглашения',
            'template_id' => 'Шаблон',
            'template_invite_id' => 'Шаблон приглашения',
            'frequency' => 'Периодичность',
            'week_days' => 'Дни недели',
            'hours_from' => 'Часы рассылки от',
            'hours_to' => 'Часы рассылки до',
            'track_views' => 'Встроить в шаблон отслеживание просмотров',
            'track_clicks' => 'Встроить в шаблон отслеживание кликов',
            'x_mailru_msgtype' => 'X-Mailru-Msgtype',
            'date' => 'Дата',
            'date_altered' => 'Дата изменения',
            'date_last_sendout' => 'Дата последней рассылки',
            'test_emails' => 'Тест рассылки по емейлам',
            'pause_reason' => 'Причина остановки',
        ];
    }

    public function init()
    {
        if (static::class == self::class) // если вызван не Search класс
            $this->loadDefaultValues();

        parent::init();
    }

    public function loadDefaultValues($skipIfSet = true)
    {
        $this->frequency = -1;
        $this->from_mail = 'noreply';
        $this->hours_from = 0;
        $this->hours_to = 24;
        $this->sources = [];
        $this->week_days = [];
        $this->send_invite = 0;

        return parent::loadDefaultValues($skipIfSet);
    }

    public function beforeSave($insert)
    {
        $this->serializeValues();
        $this->date_altered = date('Y-m-d H:i:s');

        // save domains_ids
        if (!$this->isNewRecord)
            SendoutToDomain::updateLinks($this->id, $this->domains_ids);

        return parent::beforeSave($insert);
    }

    public function afterSave($insert, $changedAttributes)
    {
        parent::afterSave($insert, $changedAttributes);

        $this->unSerializeValues();

        // если остановленная рассылка активирована, то отложенные письма активировать
        if (@$changedAttributes['status'] == static::STATUS_PAUSED && $this->status == static::STATUS_ACTIVE) {
            $n = Mail::updateAll(['status' => Mail::STATUS_AWAITS], ['status' => Mail::STATUS_DELAYED]);
            Yii::info("Sendout #{$this->id}'s mails($n) ACTIVATED.", 'mailer');
        }
        // если остановленная рассылка в черновики, то отложенные письма отменить
        elseif (@$changedAttributes['status'] == static::STATUS_PAUSED && $this->status == static::STATUS_DRAFT) {
            $n = Mail::updateAll(['status' => Mail::STATUS_CANCELLED], ['status' => Mail::STATUS_DELAYED]);
            Yii::info("Sendout #{$this->id}'s mails($n) CANCELLED.", 'mailer');
        }

    }

    public function afterFind()
    {
        $this->unSerializeValues();
        $this->domains_ids = SendoutToDomain::getSendoutDomainsIds($this->id);

        parent::afterFind();
    }

    public function afterDelete()
    {
        SendoutToDomain::deleteAll(['sendout_id' => $this->id]);
        parent::afterDelete();
    }

    public function serializeValues() {
        $this->sources = implode(',', $this->sources);
        $this->week_days = implode(',', $this->week_days);
        $this->filters = serialize($this->filters);
    }

    public function unSerializeValues() {
        $this->sources = explode(',', $this->sources);
        $this->week_days = $this->week_days ? explode(',', $this->week_days) : [];
        $this->filters = unserialize($this->filters);
    }

    public static function getTypes() {
        return [
            static::TYPE_WANTED   => 'желаемая',
            static::TYPE_UNWANTED => 'не желаемая',
        ];
    }

    public static function getYesNoList() {
        return [
            1 => 'Да',
            0 => 'Нет',
        ];
    }

    public static function getDelayList() {
        return [
            0 => 'Нет',
            1 => 'Через час',
            24 => 'Через сутки',
            48 => 'Через двое суток',
        ];
    }

    public static function getFrequencyList() {
        return [
            -1 => 'По запросу',
            0 => 'Разово',
            -2 => 'Дни недели',
            14 => 'Раз в 2 недели',
            21 => 'Раз в 3 недели',
            30 => 'Раз в месяц',
        ];
    }
    public static function getWeekDaysList() {
        return [
            1 => 'Понедельник',
            2 => 'Вторник',
            3 => 'Среда',
            4 => 'Четверг',
            5 => 'Пятница',
            6 => 'Суббота',
            7 => 'Воскресенье',
        ];
    }
    public static function getWeekDaysShortList() {
        return [
            1 => 'Пн',
            2 => 'Вт',
            3 => 'Ср',
            4 => 'Чт',
            5 => 'Пт',
            6 => 'Сб',
            7 => 'Вс',
        ];
    }

    public static function getHoursList($max = 24) {
        return range(0, $max);
    }

    public static function getLanguages() {
        return [
            'ru' => 'Русский',
            'en' => 'Английский',
            'bg' => 'Болгарский',
        ];
    }

    public static function getPriorityList() {
        return [
            -2 => '-2 низкий',
            -1 => '-1',
            0 => 'обычный',
            1 => '1',
            2 => '2',
            3 => '3',
            4 => '4',
            5 => '5 высокий',
        ];
    }

    public static function getDeviceList() {
        return [
            static::DEVICE_ANY     => 'Любой',
            static::DEVICE_DESKTOP => 'Компьютер',
            static::DEVICE_TABLET  => 'Планшет',
            static::DEVICE_PHONE   => 'Телефон',
        ];
    }
    public static function getGenderList() {
        return [
            static::GENDER_ANY    => 'Неизвестен/Любой',
            static::GENDER_MALE   => 'Мужской',
            static::GENDER_FEMALE => 'Женский',
            static::GENDER_PAIR   => 'Пара',
        ];
    }
    public static function getGenderSearchList() {
        return [
            static::GENDER_ANY    => 'Любого',
            static::GENDER_MALE   => 'Мужчину',
            static::GENDER_FEMALE => 'Женщину',
            static::GENDER_PAIR   => 'Пару',
        ];
    }
    public static function getRegistrationStatusList() {
        return [
            static::REG_STATUS_NO => 'Незарегистрирован',
            static::REG_STATUS_1  => 'Оплата 1',
            static::REG_STATUS_2  => 'Оплата 2',
        ];
    }
    public static function getThematicList() {
        return [
            static::THEMATIC_NORMAL => 'Нормальная',
            static::THEMATIC_SWING  => 'Свинг',
            static::THEMATIC_BDSM  => 'БДСМ',
            static::THEMATIC_GAY  => 'Гей',
            static::THEMATIC_LESBI  => 'Лесби',
        ];
    }
    public static function getBoolList() {
        return [
            static::BOOL_YES => 'Да',
            static::BOOL_NO  => 'Нет',
        ];
    }
    public static function getStatusList() {
        return [
            static::STATUS_ACTIVE => 'Активна',
            static::STATUS_PAUSED => 'Приостановлена',
            static::STATUS_DONE => 'Выполнена',
            static::STATUS_DRAFT => 'Черновик',
        ];
    }
    public static function getAgesList() {
        return array_combine(range(18,40), range(18,40));
    }

    /**
     * Применяет к запросу фильтры адресатов указанные в этой рассылке
     * @param Query $query
     * @return Query
     */
    public function applyFilters($query) {
        $query->leftJoin(StopMail::tableName().' stop', 'stop.subscriber_id = '.$query->from[0].'.id AND stop.sendout_id = :sendout_id', [':sendout_id'=>$this->id]);
        $query->andWhere('is_stopped = 0 AND stop.sendout_id IS NULL'); // не в стоп листе и не отписан от этой рассылки

        if (@$this->filters['not_registered']['on']) {
            $query->andWhere(
                [
                    'and',
                    ['reg' => ['none','unfinished']],
                    @$this->filters['not_registered']['country'] ? ['country_id' => $this->filters['not_registered']['country']] : [],
                    @$this->filters['not_registered']['region'] ? ['region_id' => $this->filters['not_registered']['region']] : [],
                    @$this->filters['not_registered']['city'] ? ['city_id' => $this->filters['not_registered']['city']] : [],
                    @$this->filters['not_registered']['device'] ? ['device' => $this->filters['not_registered']['device']] : [],
                    @$this->filters['not_registered']['suggested_gender'] ? ['sex' => $this->filters['not_registered']['suggested_gender']] : [],
                    @$this->filters['not_registered']['registration_status'] ? ['promo' => $this->filters['not_registered']['registration_status'] == 'no' ? null : $this->filters['not_registered']['registration_status']] : [],
                    @$this->filters['not_registered']['thematic'] ? ['thematic' => $this->filters['not_registered']['thematic']] : [],
                    @$this->filters['not_registered']['suggested_search'] ? ['search' => $this->filters['not_registered']['suggested_search']] : [],
                ]
            );
        }
        if (@$this->filters['registered']['on']) {
            $query->orWhere(
                [
                    'and',
                    ['reg' => ['registered']],
                    @$this->filters['registered']['country'] ? ['country_id' => $this->filters['registered']['country']] : [],
                    @$this->filters['registered']['region'] ? ['region_id' => $this->filters['registered']['region']] : [],
                    @$this->filters['registered']['city'] ? ['city_id' => $this->filters['registered']['city']] : [],
                    @$this->filters['registered']['notification'] ? ['email_confirmed' => $this->filters['registered']['notification']] : [],
                    @$this->filters['registered']['gender'] ? ['sex' => $this->filters['registered']['gender']] : [],
                    @$this->filters['registered']['lastVisit'] ? ['>=', 'lastvisit', date('Y-m-d H:i:s', time() - 60*60*24 * $this->filters['registered']['lastVisit'])] : [],
                    @$this->filters['registered']['rebill'] ? ['rebill' => $this->filters['registered']['rebill']] : [],
                    @$this->filters['registered']['search'] ? ['search' => $this->filters['registered']['search']] : [],
                    @$this->filters['registered']['age_from'] ? ['<=', 'birth_date', date('Y-m-d H:i:s', time() - 60*60*24*365 * $this->filters['registered']['age_from'])] : [],
                    @$this->filters['registered']['age_to'] ? ['>=', 'birth_date', date('Y-m-d H:i:s', time() - 60*60*24*365 * $this->filters['registered']['age_to'])] : [],
                ]
            );
        }

        if ($this->sources && $this->sources[0]) {
            $query->andFilterWhere(['in', 'source', $this->sources]);
        }

        return $query;
    }

    /**
     * @return string условия для WHERE, для запроса подписчиков
     * @see applyFilters()
     */
    public function getFiltersWhereClause() {
        $q = $this->applyFilters(new Query)->createCommand()->rawSql;
        $where = @explode(' WHERE ', $q)[1];
        $where = $where ?: '1=1';
        return $where;
    }

    /**
     * @return bool true если у рассылки не выбраны фильтры подписчиков.
     */
    public function getIfNoFiltering() {
        return !(@$this->filters['not_registered']['on'] || @$this->filters['registered']['on'] || $this->sources && $this->sources[0]);
    }
    /**
     * @return int кол-во подписчиков этой рассылки.
     */
    public function countSubscribers() {
        $query = (new Query())
            ->select('count(*)')
            ->from(Subscriber::tableName());

        $query = $this->applyFilters($query);

        return intval($query->scalar());
    }

    /**
     * @return array
     */
    public function getSubscribers() {
        $query = (new Query())
            ->select('*')
            ->from(Subscriber::tableName());

        $query = $this->applyFilters($query);
        return $query->all();
    }

    /**
     * @param Subscriber $subscriber
     * @return bool
     * @see applyFilters()
     */
    public function canSendTo(Subscriber $subscriber) {
        if ($this->status != static::STATUS_ACTIVE)
            return false;

        $query = (new Query())
            ->select('id')
            ->from(Subscriber::tableName())
            ->limit(1);

        $query = $this->applyFilters($query);
        $query->andFilterWhere(['id' => $subscriber->id]);

        return boolval($query->scalar());
    }

    /**
     * Определяет пора ли производить эту рассылку (зависит от периодичности $this->frequency)
     * @return bool
     */
    public function isTimeToSendout() {
        if ($this->status != static::STATUS_ACTIVE) // не надо рассылать
            return false;

        if ($this->frequency == 0) // разовая рассылка
            return true;

        if ($this->frequency == -1) // рассылка только по API запросу
            return false;

        if ($this->frequency == -2) {// рассылка по дням недели
            // сегодня день рассылки и (рассылка еще не отправлялась или сегодня еще не отправлялась)
            if (in_array(date('N'), $this->week_days) && (!$this->date_last_sendout || substr($this->date_last_sendout,0,10) != date('Y-m-d')))
                return true;
            else
                return false;
        }

        if (!$this->date_last_sendout) // еще ни разу не рассылалась
            return true;

        if ($this->getDaysFromLastSendout() >= $this->frequency) // пора рассылась снова
            return true;

        return false;
    }

    /**
     * @return int|null кол-во дней прошедших с последней рассылки
     */
    public function getDaysFromLastSendout() {
        if (!$this->date_last_sendout) return null;

        $date = DateTime::createFromFormat('Y-m-d H:i:s', $this->date_last_sendout);
        return $date->diff(new DateTime)->days;
    }

    /**
     * Для этой рассылки, создает письма в очереди на отправку, если надо (рассылка активна) и уже пора (рассылка периодическая).
     * Письма приглашения создаются независимо от периодичности.
     * Обновляет время рассылки и если рассылка разовая то статус => выполнена.
     *
     * @return int|null число созданных в очереди писем или null если майлинг отключен глобально
     * @throws \yii\db\Exception
     */
    public function scheduleMails() {
        if (@Yii::$app->params['mailer_disabled'])
            return null;

        $scheduled = 0;

        if ($this->send_invite && $this->status == static::STATUS_ACTIVE)
            $scheduled += $this->sendInvites(); // независимо от периодичности (isTimeToSendout()) отправляем приглашения, кому еще не слали

        if (!$this->isTimeToSendout())
            return $scheduled;

        // обновляем статус и время последней рассылки, чтобы другой поток не продублировал её
        $this->date_last_sendout = date('Y-m-d H:i:s');
        if ($this->frequency == 0)
            $this->status = static::STATUS_DONE; // разовая рассылка выполнена
        $this->save(false, ['date_last_sendout','status']);

        // берем подходящих подписчиков и создаем для них письма в очереди
        if ($this->send_invite)
            $where = 'email_confirmed = "1" AND '.$this->getFiltersWhereClause();
        else
            $where = $this->getFiltersWhereClause();

        //TODO: связать hour_from и hour_to с час.поясом адресата для отправки в нужное время суток
        $scheduled += $this->getDb()
            ->createCommand(
                'INSERT INTO mail (priority, sendout_id, subscriber_id, is_invite, date_created, hour_from, hour_to)
                 SELECT :priority, :sendout, id, 0, NOW(),
                        :hour_from, :hour_to
                 FROM subscriber s
                 LEFT JOIN `stop_mail` `stop` ON stop.subscriber_id = s.id AND stop.sendout_id = :sendout
                 WHERE '.$where,
                [
                    ':priority' => $this->priority,
                    ':sendout' => $this->id,
                    ':hour_from' => $this->hours_from,
                    ':hour_to' => $this->hours_to,
                ])
            ->execute();

        return $scheduled;
    }

    /**
     * Разослать все рассылки, которые пора.
     * @return int|null число созданных в очереди писем или null если это отключего глобально
     * @throws \yii\db\Exception
     */
    public static function scheduleAll() {
        if (@Yii::$app->params['mailer_disabled'])
            return null;

        $mailsNum = 0;

        $transaction = Yii::$app->db->beginTransaction();

        $sendouts = Sendout::findBySql("SELECT * FROM sendout WHERE status='active' ORDER BY priority DESC FOR UPDATE")->all();

        /* @var Sendout[] $sendouts */
        foreach ($sendouts as $sendout) {
            $mailsNum += $sendout->scheduleMails();
        }

        $transaction->commit();

        return $mailsNum;
    }

    /**
     * Для этой рассылки, создает письма-приглашения в очереди на отправку, если рассылка активна.
     * Письма-приглашения создаются только для тех кому еще не слали.
     *
     * @return int|null число созданных в очереди писем или null если майлинг отключен
     * @throws \yii\db\Exception
     */
    public function sendInvites() {
        // берем подходящих подписчиков и создаем для них письма-приглашения в очереди
        $where = 'email_confirmed = "0" AND is_invite_sended = 0 AND '.$this->getFiltersWhereClause();
        //TODO: связать hour_from и hour_to с час.поясом адресата для отправки в нужное время суток
        $affected = $this->getDb()
            ->createCommand(
                'INSERT INTO mail (priority, sendout_id, subscriber_id, is_invite, date_created, date_scheduled)
                 SELECT :priority, :sendout, id, 1, NOW(), IF(:delay_hours <= 0, NULL, DATE_ADD(s.date, INTERVAL :delay_hours HOUR))
                 FROM subscriber s
                 LEFT JOIN `stop_mail` `stop` ON stop.subscriber_id = s.id AND stop.sendout_id = :sendout
                 WHERE '.$where,
                [
                    ':priority' => $this->priority,
                    ':sendout' => $this->id,
                    ':delay_hours' => $this->invite_delay_hours,
                ])
            ->execute();

        // отмечаем у подписчиков, что приглашения им высланы
        $this->getDb()
            ->createCommand(
                'UPDATE subscriber s
                 LEFT JOIN `stop_mail` `stop` ON stop.subscriber_id = s.id AND stop.sendout_id = :sendout
                 SET is_invite_sended = 1
                 WHERE '.$where,
                [
                    ':sendout' => $this->id,
                ])
            ->execute();

        return $affected;
    }

    /**
     * Дает ID доменов этой рассылки.
     * @return int[]
     */
    public function getDomainsIds()
    {
        return SendoutToDomain::getSendoutDomainsIds($this->id);
    }

    /**
     * Выдает подходящий домен для рассылки писем.
     *
     * @param bool $throwException кидать ли Exception.
     * @param null $errorMessage заполняется если холодный IP не найден
     * @return Domain|Null подходящий домен или Null если нет подходящего
     * @throws Exception если $exception и нет подходящего
     */
    public function getProperDomain($throwException = true, &$errorMessage = null) {
        // получение всех верифицированных доменов рассылки
        $domains_ids = $this->getDomainsIds();
        $domains = Domain::find()->verified()->andWhere(['id' => $domains_ids])->all();

        if (!$domains)
        {
            $ids = implode(',', $this->domains_ids);
            if ($throwException) {
                throw new Exception("Verified domain ($ids) not found for sendout #{$this->id}.");
            }
            else {
                return null;
            }
        }

        // нахождение домена с непрывышенным лимитом у IP
        foreach ($domains as $domain) {
            if ($domain->getProperIp(false, $throwException)) {
                return $domain;
            }
        }

        if ($throwException) {
            throw new Exception("Verified domain with not exceed IPs not found for sendout #{$this->id}.");
        }

        $errorMessage = 'Для рассылки не найден домен с IP без превышения лимита.';

        return null;
    }

    /**
     * @return array емейлы из поля test_emails
     */
    public function getTestEmails() {
        $emails = preg_split('/[\s,;]+/', $this->test_emails);
        $r = [];
        foreach ($emails as $email)
            if ($email) $r[] = $email;

        return $r;
    }

    /**
     * Приостанавливает рассылку.
     * @param string $reason
     */
    public function pause($reason) {
        $this->status = static::STATUS_PAUSED;
        $this->pause_reason = $reason;
        $this->save(false);
    }

    /**
     * @param Subscriber $subscriber
     * @param null $mail_id
     */
    public function pingUnsubscribe($subscriber, $mail_id = null) {
        $ping_url = str_replace([
            '{mail_id}',
            '{sendout_id}',
            '{subscriber_id}',
            '{email}',
        ], [
            $mail_id,
            $this->id,
            $subscriber->id,
            $subscriber->email,
        ], $this->unsubscribe_ping_url);

        if (!$ping_url) return;

        // todo: CURLOPT_NOSIGNAL чтобы не ждать ответа?
        $curl = new Curl;
        $curl->setUserAgent('MailerPing');

        $curl->get($ping_url);
    }

    /**
     * Дает шаблон для страницы подписки или отписки.
     * @param string $type
     * @return null|Template
     */
    public function getPageTemplate($type) {
        return Template::find()->where([
            'type' => $type,
            'site_id' => $this->site_id,
            'lang' => $this->lang,
        ])->one();
    }
}
