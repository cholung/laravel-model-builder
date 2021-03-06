<?php

namespace Jimbolino\Laravel\ModelBuilder;

use Exception;
use ReflectionClass;

/**
 * Class Model, a representation of one Laravel model.
 */
class Model
{
    // input
    private $baseModel = 'Model';
    private $table = '';
    private $foreignKeys = [];

    // the class and table names
    private $class = '';

    // auto detected the elements
    private $timestampFields = [];
    private $primaryKey = '';
    private $incrementing = false;
    private $timestamps = false;
    private $dates = [];
    private $hidden = [];
    private $fillable = [];
    private $namespace = '';

    /**
     * @var Relations
     */
    private $relations;

    // the result
    private $fileContents = '';

    /**
     * First build the model.
     *
     * @param $table
     * @param $baseModel
     * @param $describes
     * @param $foreignKeys
     * @param string $namespace
     * @param string $prefix
     */
    public function buildModel($table, $baseModel, $describes, $foreignKeys, $namespace = '', $prefix = '')
    {
        $this->table = StringUtils::removePrefix($table, $prefix);
        $this->baseModel = $baseModel;
        $this->foreignKeys = $this->filterAndSeparateForeignKeys($foreignKeys['all'], $table);
        $foreignKeysByTable = $foreignKeys['ordered'];

        if (!empty($namespace)) {
            $this->namespace = "\n".'namespace '.$namespace.';';
        }

        $this->class = StringUtils::prettifyTableName($table, $prefix);
        $this->timestampFields = $this->getTimestampFields($this->baseModel);

        $describe = $describes[$table];

        // main loop
        foreach ($describe as $field) {
            if ($this->isPrimaryKey($field)) {
                $this->primaryKey = $field->Field;
                $this->incrementing = $this->isIncrementing($field);
                continue;
            }

            if ($this->isTimestampField($field)) {
                $this->timestamps = true;
                continue;
            }

            if ($this->isDate($field)) {
                $this->dates[] = $field->Field;
            }

            if ($this->isHidden($field)) {
                $this->hidden[] = $field->Field;
                continue;
            }

            if ($this->isForeignKey($table, $field->Field)) {
                continue;
            }

            $this->fillable[] = $field->Field;
        }

        // relations
        $this->relations = new Relations(
            $table,
            $this->foreignKeys,
            $describes,
            $foreignKeysByTable,
            $prefix,
            $namespace
        );
    }

    /**
     * Secondly, create the model.
     */
    public function createModel()
    {
        $file = '<?php'.LF;

        $file .= $this->namespace.LF.LF;

        // Try to render everything neat
        $baseModel = 'Model';
        $modelRoute = $this->baseModel;
        if(substr($modelRoute, 0, 1) == '\\')
        {
            $modelRoute = substr($modelRoute, 1);
        }
        $file .= 'use '.$modelRoute.';'.LF.LF;

        $file .= '/**'.LF;
        $file .= ' * Eloquent class to describe the '.$this->table.' table'.LF;
        $file .= ' *'.LF;
        $file .= ' * automatically generated by ModelGenerator.php'.LF;
        $file .= ' */'.LF;

        // a new class that extends the provided baseModel
        $file .= 'class '.$this->class.' extends '.$baseModel.LF;
        $file .= '{'.LF;

        // the name of the mysql table
        $file .= TAB.'/**'.LF;
        $file .= TAB.' * The database table used by the model.'.LF;
        $file .= TAB.' *'.LF;
        $file .= TAB.' * @var string'.LF;
        $file .= TAB.' */'.LF;
        $file .= TAB.'protected $table = '.StringUtils::singleQuote($this->table).';'.LF.LF;

        // primary key defaults to "id"
        if ($this->primaryKey !== 'id') {
            $file .= TAB.'/**'.LF;
            $file .= TAB.' * Primary key defaults to id.'.LF;
            $file .= TAB.' *'.LF;
            $file .= TAB.' * @var string'.LF;
            $file .= TAB.' */'.LF;
            $file .= TAB.'public $primaryKey = '.StringUtils::singleQuote($this->primaryKey).';'.LF.LF;
        }

        // timestamps defaults to true
        if (!$this->timestamps) {
            $file .= TAB.'/**'.LF;
            $file .= TAB.' * Indicates if the model should be timestamped.'.LF;
            $file .= TAB.' *'.LF;
            $file .= TAB.' * @var bool'.LF;
            $file .= TAB.' */'.LF;
            $file .= TAB.'public $timestamps = '.var_export($this->timestamps, true).';'.LF.LF;
        }

        // incrementing defaults to true
        if (!$this->incrementing) {
            $file .= TAB.'/**'.LF;
            $file .= TAB.' * Indicates if the model should be incremented.'.LF;
            $file .= TAB.' *'.LF;
            $file .= TAB.' * @var bool'.LF;
            $file .= TAB.' */'.LF;
            $file .= TAB.'public $incrementing = '.var_export($this->incrementing, true).';'.LF.LF;
        }

        // all date fields
        if (!empty($this->dates)) {
            $file .= TAB.'/**'.LF;
            $file .= TAB.' * All date fields of the model.'.LF;
            $file .= TAB.' *'.LF;
            $file .= TAB.' * @return array'.LF;
            $file .= TAB.' */'.LF;
            $file .= TAB.'public function getDates()'.LF;
            $file .= TAB.'{'.LF;
            $file .= TAB.TAB.'return ['.LF.TAB.TAB.TAB.StringUtils::implodeAndQuote(','.LF.TAB.TAB.TAB, $this->dates).LF.TAB.TAB.'];'.LF;
            $file .= TAB.'}'.LF.LF;
        }

        // most fields are considered as fillable
        $file .= TAB.'/**'.LF;
        $file .= TAB.' * The attributes that are mass assignable.'.LF;
        $file .= TAB.' *'.LF;
        $file .= TAB.' * @var array'.LF;
        $file .= TAB.' */'.LF;
        // Filler for a better rendered code on empty results
        if(empty($this->fillable))
        {
            $fillResults = '//';
        }
        else
        {
            $fillResults = StringUtils::implodeAndQuote(','.LF.TAB.TAB, $this->fillable);
        }
        $wrap = TAB.'protected $fillable = ['.LF.TAB.TAB.$fillResults.LF.TAB.'];'.LF.LF;
        $file .= wordwrap($wrap, ModelGenerator::$lineWrap, LF.TAB.TAB);

        // except for the hidden ones
        if (!empty($this->hidden)) {
            $file .= TAB.'/**'.LF;
            $file .= TAB.' * The attributes excluded from the model\'s JSON form.'.LF;
            $file .= TAB.' *'.LF;
            $file .= TAB.' * @var array'.LF;
            $file .= TAB.' */'.LF;
            $file .= TAB.'protected $hidden = ['.LF.TAB.TAB.StringUtils::implodeAndQuote(','.LF.TAB.TAB, $this->hidden).LF.TAB.'];'.LF.LF;
        }

        // add all relations
        $file .= $this->relations;

        // close the class
        $file .= '}'.LF.LF;

        $this->fileContents = $file;
    }

    /**
     * Thirdly, return the created string.
     *
     * @return string
     */
    public function __toString()
    {
        return $this->fileContents;
    }

    /**
     * Detect if we have timestamp field
     * TODO: not sure about this one yet.
     *
     * @param $model
     *
     * @return array
     */
    protected function getTimestampFields($model)
    {
        try {
            $baseModel = new ReflectionClass($model);
            $timestampFields = [
                'created_at' => $baseModel->getConstant('CREATED_AT'),
                'updated_at' => $baseModel->getConstant('UPDATED_AT'),
                'deleted_at' => $baseModel->getConstant('DELETED_AT'),
            ];
        } catch (Exception $e) {
            echo 'baseModel: '.$model.' not found'.LF;
            $timestampFields = [
                'created_at' => 'created_at',
                'updated_at' => 'updated_at',
                'deleted_at' => 'deleted_at',
            ];
        }

        return $timestampFields;
    }

    /**
     * Check if the field is primary key.
     *
     * @param $field
     *
     * @return bool
     */
    protected function isPrimaryKey($field)
    {
        if ($field->Key == 'PRI') {
            return true;
        }

        return false;
    }

    /**
     * Check if the field (primary key) is auto incrementing.
     *
     * @param $field
     *
     * @return bool
     */
    protected function isIncrementing($field)
    {
        if ($field->Extra == 'auto_increment') {
            return true;
        }

        return false;
    }

    /**
     * Check if we have timestamp field.
     *
     * @param $field
     *
     * @return bool
     */
    protected function isTimestampField($field)
    {
        if (array_search($field->Field, $this->timestampFields)) {
            return true;
        }

        return false;
    }

    /**
     * Check if we have a date field.
     *
     * @param $field
     *
     * @return bool
     */
    protected function isDate($field)
    {
        if (StringUtils::strContains(['date', 'time', 'year'], $field->Type)) {
            return true;
        }

        return false;
    }

    /**
     * Check if we have a hidden field.
     *
     * @param $field
     *
     * @return bool
     */
    protected function isHidden($field)
    {
        if (StringUtils::strContains(['hidden', 'secret'], $field->Comment)) {
            return true;
        }

        return false;
    }

    /**
     * Check if we have a foreign key.
     *
     * @param $table
     * @param $field
     *
     * @return bool
     */
    protected function isForeignKey($table, $field)
    {
        foreach ($this->foreignKeys['local'] as $entry) {
            if ($entry->COLUMN_NAME == $field && $entry->TABLE_NAME == $table) {
                return true;
            }
        }

        return false;
    }

    /**
     * Only show the keys where table is mentioned.
     *
     * @param $foreignKeys
     * @param $table
     *
     * @return array
     */
    protected function filterAndSeparateForeignKeys($foreignKeys, $table)
    {
        $results = ['local' => [], 'remote' => []];
        foreach ($foreignKeys as $foreignKey) {
            if ($foreignKey->TABLE_NAME == $table) {
                $results['local'][] = $foreignKey;
            }
            if ($foreignKey->REFERENCED_TABLE_NAME == $table) {
                $results['remote'][] = $foreignKey;
            }
        }

        return $results;
    }
}
