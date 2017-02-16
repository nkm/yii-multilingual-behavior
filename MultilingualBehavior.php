<?php

/**
 * MultilingualBehavior handles active record model translation.
 *
 * This behavior allow you to create multilingual models and to use them (almost) like normal models.
 * For each model, translations have to be stored in a separate table of the database (ex: PostLang or ProductLang),
 * which allow you to easily add or remove a language without modifying your database.
 *
 * @author guillemc, Frédéric Rocheron <frederic.rocheron@gmail.com>
 * @author nkm, Javier Zapata <javierzapata82@gmail.com>
 * @license Unlicensed
 */
class MultilingualBehavior extends CActiveRecordBehavior
{
    /**
     * The name of translation model class.
     *
     * @var string
     */
    public $localizedModelName;

    /**
     * Default suffix for localized models.
     *
     * @var string
     */
    private $localizedModelSuffix = 'Localized';

    /**
     * The name of the translation table.
     *
     * @var string
     */
    public $localizedTableName;

    /**
     * Default suffix for localized tables.
     *
     * @var string
     */
    private $localizedTableSuffix = '_localized';

    /**
     * The name of the foreign key field of the translation table
     * related to base model table.
     *
     * Default to '[base model table name]_id'.
     *
     * Example: for a base model table named post', the translation model
     * table name is 'post_id'.
     *
     * @var string
     */
    public $localizedForeignKey;

    /**
     * The name of the language on the translation table.
     *
     * @var string
     */
    public $languageField = 'language';

    /**
     * The attributes of the base model to be translated.
     *
     * Example: ['title', 'content']
     *
     * @var array
     */
    public $localizedAttributes;

    /**
     * The prefix of the localized attributes in the localized table
     * to avoid collisions in queries.
     *
     * In the translation table, the columns corresponding to the localized
     * attributes have to be named like this: 'localized_[name of the attribute]'
     * and the id column (primary key) like this: 'id'
     *
     * @var string
     */
    private $localizedPrefix = 'localized_';

    /**
     * The languages to use.
     *
     * It can be a simple array: [fr', 'en']
     * or an associative array: [fr' => 'Français', 'en' => 'English']
     *
     * For associative arrays, only the keys will be used.
     *
     * @var array
     */
    public $languages;

    /**
     * The default language.
     * Example: 'en'
     *
     * @var string
     */
    public $defaultLanguage = 'en';

    /**
     * The scenario corresponding to the creation of the model.
     *
     * @var array
     */
    public $createScenarios = ['insert'];

    /**
     * The name the relation that is used to get translated attributes values
     * for the current language.
     *
     * Default to 'localized[base model class name]'.
     *
     * Example: for a base model class named 'Post', the relation name is 'localizedPost'.
     *
     * @var string
     */
    public $localizedRelationName;

    /**
     * @var string
     */
    private $localizedRelationPrefix = 'localized';

    /**
     * The name the relation that is used to all translations
     * for all translated attributes.
     *
     * Used to have access to all translations at once, for example when you
     * want to display form to update a model.
     *
     * Every translation for an attribute can be accessed like this:
     *     $model->[name of the attribute]_[language code]
     *     (example: $model->title_en, $model->title_fr).
     *
     * Default to 'internationalized[base model class name]'.
     *
     * Example: for a base model table named post', the relation name is 'internationalizedPost'.
     *
     * @var string
     */
    public $internationalizedRelationName;

    /**
     * @var string
     */
    private $internationalizedRelationPrefix = 'internationalized';

    /**
     * Wether to force overwrite of the default language value
     * with translated value even if it is empty.
     *
     * Used only for {@link localizedRelationName}.
     *
     * @var boolean
     */
    public $forceOverwrite = false;

    /**
     * Wether to force deletion of the associated translations
     * when a base model is deleted.
     *
     * Not needed if using foreign key with 'on delete cascade'.
     *
     * @var boolean
     */
    public $forceDelete = true;

    /**
     * Wether to dynamically create translation model class.
     *
     * If true, the translation model class will be generated on runtime
     * with the use of the eval() function so no additionnal php file is needed.
     *
     * See {@link createLocalizedModel()}
     *
     * @var boolean
     */
    public $dynamicLocalizedModel = true;

    /**
     * [$langAttributes description]
     *
     * @var array
     */
    private $langAttributes = [];

    /**
     * [$notDefaultLanguage description]
     *
     * @var boolean
     */
    private $notDefaultLanguage = false;

    /**
     * [createLocalizedRelation description]
     *
     * @param  CModel $owner The model on which create the relation
     * @param  string $lang  The code of the language to localize
     * @return void
     */
    private function createLocalizedRelation(CModel $owner, $lang)
    {
        $class = CActiveRecord::HAS_MANY;
        $owner
        ->getMetaData()
        ->relations[$this->localizedRelationName] = new $class(
            $this->localizedRelationName,
            $this->localizedModelName,
            $this->localizedForeignKey,
            [
                'on'    => $this->localizedRelationName.'.'.$this->languageField."='".$lang."'",
                'index' => $this->languageField,
            ]
        );
    }

    /**
     * Attach the behavior to the model.
     *
     * @param CModel $owner
     */
    public function attach($owner)
    {
        parent::attach($owner);
        $ownerClassname  = get_class($owner);
        $tableNameChunks = explode('.', $owner->tableName());
        $simpleTableName = str_replace(['{{', '}}'], '', array_pop($tableNameChunks));

        if (!isset($this->localizedModelName)) {
            $this->localizedModelName = $ownerClassname.$this->localizedModelSuffix;
        }

        if (!isset($this->localizedTableName)) {
            $this->localizedTableName = $simpleTableName.$this->localizedTableSuffix;
        }

        if (!isset($this->localizedRelationName)) {
            $this->localizedRelationName = $this->localizedRelationPrefix.$ownerClassname;
        }

        if (!isset($this->internationalizedRelationName)) {
            $this->internationalizedRelationName = $this->internationalizedRelationPrefix.$ownerClassname;
        }

        if (!isset($this->localizedForeignKey)) {
            $this->localizedForeignKey = $simpleTableName.'_id';
        }

        if ($this->dynamicLocalizedModel) {
            $this->createLocalizedModel();
        }

        if (!isset($this->languages)) {
            $this->languages = Yii::app()->params->languages;
        }

        if (array_values($this->languages) !== $this->languages) { // associative array
            $this->languages = array_keys($this->languages);
        }

        $class = CActiveRecord::HAS_MANY;
        $this->createLocalizedRelation($owner, Yii::app()->language);
        $owner
        ->getMetaData()
        ->relations[$this->internationalizedRelationName] = new $class(
            $this->internationalizedRelationName,
            $this->localizedModelName,
            $this->localizedForeignKey,
            ['index' => $this->languageField]
        );

        $rules = $owner->rules();
        $validators = $owner->getValidatorList();
        foreach ($this->languages as $l) {
            foreach ($this->localizedAttributes as $attr) {
                foreach ($rules as $rule) {
                    $ruleAttributes = array_map('trim', explode(',', $rule[0]));
                    if (in_array($attr, $ruleAttributes)) {
                        if ($rule[1] !== 'required' || $this->forceOverwrite) {
                            $validators
                            ->add(CValidator::createValidator(
                                $rule[1],
                                $owner,
                                $attr.'_'.$l,
                                array_slice($rule, 2)
                            ));
                        } elseif ($rule[1] === 'required') {
                            // We add a safe rule in case the attribute has only
                            // a 'required' validation rule assigned
                            // and forceOverWrite == false
                            $validators
                            ->add(CValidator::createValidator(
                                'safe',
                                $owner,
                                $attr.'_'.$l,
                                array_slice($rule, 2)
                            ));
                        }
                    }
                }
            }
        }
    }

    /**
     * Dynamically create the translation model class with the use of the eval()
     * function so no additionnal php file is needed.
     *
     * The translation model class created is a basic active record model
     * corresponding to the translations table.
     *
     * It includes a BELONG_TO relation to the base model which allows
     * advanced usage of the translation model like conditional find
     * on translations to retrieve base model.
     *
     * @return void
     */
    public function createLocalizedModel() {
        if (class_exists($this->localizedModelName, false)) return;

        $ownerClassname = get_class($this->getOwner());
        eval("class {$this->localizedModelName} extends CActiveRecord
        {
            public static function model(\$className=__CLASS__)
            {
                return parent::model(\$className);
            }

            public function tableName()
            {
                return '{$this->localizedTableName}';
            }

            public function relations()
            {
                return ['{$ownerClassname}' => [self::BELONGS_TO, '{$ownerClassname}', '{$this->localizedForeignKey}']];
            }
        }");
    }

    /**
     * Named scope to use {@link localizedRelationName}
     *
     * @param string $lang the lang the retrieved models will be translated
     *                     (default to current application language)
     * @return CModel
     */
    public function localized($lang = null) {
        $owner = $this->getOwner();
        if (
            $lang != null &&
            $lang != Yii::app()->language &&
            in_array($lang, $this->languages)
        ) {
            $this->createLocalizedRelation($owner, $lang);
            $this->notDefaultLanguage = true;
        }

        $owner->getDbCriteria()->mergeWith(
            $this->localizedCriteria()
        );

        return $owner;
    }

    /**
     * Named scope to use {@link internationalizedRelationName}
     *
     * @return CModel
     */
    public function multilang() {
        $owner = $this->getOwner();
        $owner->getDbCriteria()->mergeWith(
            $this->multilangCriteria()
        );

        return $owner;
    }

    /**
     * Array of criteria to use {@link localizedRelationName}
     *
     * @return array
     */
    public function localizedCriteria() {
        return [
            'with' => [
                $this->localizedRelationName => [],
            ],
        ];
    }

    /**
     * Array of criteria to use {@link internationalizedRelationName}
     *
     * @return array
     */
    public function multilangCriteria() {
        return [
            'with' => [
                $this->internationalizedRelationName => [],
            ],
        ];
    }

    /**
     * Wether the attribute exists
     *
     * @param string $name the name of the attribute
     * @return boolean
     */
    public function hasLangAttribute($name) {
        return array_key_exists($name, $this->langAttributes);
    }

    /**
     * @param string $name the name of the attribute
     * @return string the attribute value
     */
    public function getLangAttribute($name) {
        return $this->hasLangAttribute($name)
             ? $this->langAttributes[$name]
             : null;
    }

    /**
     * @param string $name the name of the attribute
     * @param string $value the value of the attribute
     */
    public function setLangAttribute($name, $value) {
        $this->langAttributes[$name] = $value;
    }

    /**
     * {@inheritdoc}
     */
    public function afterConstruct() {
        $owner = $this->getOwner();
        if (in_array($owner->scenario, $this->createScenarios)) {
            $owner = new $this->localizedModelName;
            foreach ($this->languages as $lang) {
                foreach ($this->localizedAttributes as $field) {
                    $ownerfield = $this->localizedPrefix.$field;
                    $this->setLangAttribute($field.'_'.$lang, $owner->$ownerfield);
                }
            }
        }
    }

    /**
     * Modify passed criteria by replacing conditions on base attributes with conditions on translations.
     * Allow to make search on model translated values.
     *
     * @param CDbCriteria $criteria
     * @return CDbCriteria
     */
    public function modifySearchCriteria(CDbCriteria $criteria) {
        $owner         = $this->getOwner();
        $criteriaArray = $criteria->toArray();

        foreach ($this->localizedAttributes as $attribute) {
            if (!empty($owner->$attribute)) {
                $criteriaArray['condition'] = str_replace(
                    $attribute.' ',
                    $this->localizedPrefix.$attribute.' ',
                    $criteriaArray['condition']
                );
            }
        }

        $criteriaArray['together'] = true;
        $criteria = new CDbCriteria($criteriaArray);

        return $criteria;
    }

    /**
     * {@inheritdoc}
     */
    public function afterFind() {
        $owner = $this->getOwner();

        if ($owner->hasRelated($this->internationalizedRelationName)) {
            $related = $owner->getRelated($this->internationalizedRelationName);

            foreach ($this->languages as $lang) {
                foreach ($this->localizedAttributes as $field) {
                    $this->setLangAttribute(
                        $field.'_'.$lang,
                        isset($related[$lang][$this->localizedPrefix.$field])
                            ? $related[$lang][$this->localizedPrefix.$field]
                            : null
                    );
                }
            }
        } elseif ($owner->hasRelated($this->localizedRelationName)) {
            $related = $owner->getRelated($this->localizedRelationName);

            if ($row = current($related)) {
                foreach ($this->localizedAttributes as $field) {
                    if (
                        isset($owner->$field) &&
                        (!empty($row[$this->localizedPrefix.$field]) || $this->forceOverwrite)
                    ) {
                        $owner->$field = $row[$this->localizedPrefix.$field];
                    }
                }
            }

            if ($this->notDefaultLanguage) {
                $this->createLocalizedRelation($owner, Yii::app()->language);
                $this->notDefaultLanguage = false;
            }
        }

    }

    /**
     * {@inheritdoc}
     */
    public function afterSave()
    {
        $mainOwner = $this->getOwner();
        $ownerPk   = $mainOwner->getPrimaryKey();
        $rs        = [];

        if (!$mainOwner->isNewRecord) {
            $model = call_user_func([$this->localizedModelName, 'model']);

            $c = new CDbCriteria();
            $c->condition = "{$this->localizedForeignKey}=:id";
            $c->params    = ['id' => $ownerPk];
            $c->index     = $this->languageField;

            $rs = $model->findAll($c);
        }

        foreach ($this->languages as $lang) {
            $defaultLanguage = $lang == $this->defaultLanguage;

            if (!isset($rs[$lang])) {
                $owner = new $this->localizedModelName;
                $owner->{$this->languageField} = $lang;
                $owner->{$this->localizedForeignKey} = $ownerPk;
            } else {
                $owner = $rs[$lang];
            }

            foreach ($this->localizedAttributes as $field) {
                $value = $defaultLanguage
                       ? $mainOwner->$field
                       : $this->getLangAttribute($field.'_'.$lang);

                if ($value !== null) {
                    $localizedField = $this->localizedPrefix.$field;
                    $owner->$localizedField = $value;
                }
            }

            $owner->save(false);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function afterDelete()
    {
        if ($this->forceDelete) {
            $ownerPk = $this->getOwner()->getPrimaryKey();

            $model = call_user_func([$this->localizedModelName, 'model']);
            $model->deleteAll("{$this->localizedForeignKey} = :id", ['id' => $ownerPk]);
        }

        parent::afterDelete();
    }

    /**
     * {@inheritdoc}
     */
    public function __get($name)
    {
        try {
            return parent::__get($name);
        } catch (CException $e) {
            if ($this->hasLangAttribute($name)) {
                return $this->getLangAttribute($name);
            } else {
                throw $e;
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function __set($name, $value)
    {
        try {
            parent::__set($name, $value);
        } catch (CException $e) {
            if ($this->hasLangAttribute($name)) {
                $this->setLangAttribute($name, $value);
            }
            else {
                throw $e;
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function __isset($name)
    {
        if (!parent::__isset($name)) {
            return ($this->hasLangAttribute($name));
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function canGetProperty($name)
    {
        return parent::canGetProperty($name) || $this->hasLangAttribute($name);
    }

    /**
     * {@inheritdoc}
     */
    public function canSetProperty($name)
    {
        return parent::canSetProperty($name) || $this->hasLangAttribute($name);
    }

    /**
     * {@inheritdoc}
     */
    public function hasProperty($name)
    {
        return parent::hasProperty($name) || $this->hasLangAttribute($name);
    }
}
