<?php

namespace app\models;

use yii\mongodb\ActiveRecord;

class ImportLog extends ActiveRecord
{
    public static function CollectionName()
    {
        return 'import_logs';
    }

    public static function model($className = __CLASS__)
    {
        return parent::model($className);
    }

    public function attributes()
    {
        return [
            '_id', 'label', 'count', 'at', 'message', 'jobId', 'created_at'
        ];
    }
}
