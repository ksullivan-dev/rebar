<?php
namespace Fluxoft\Rebar\Db;

/**
 * @property \Fluxoft\Rebar\Db\Providers\Provider Reader
 * @property \Fluxoft\Rebar\Db\Providers\Provider Writer
 */
class ModelFactory {
	/**
	 * The provider used to read from the database.
	 * @var \Fluxoft\Rebar\Db\Providers\Provider
	 */
	protected $reader = null;
	/**
	 * The provider used to write to the database.
	 * @var \Fluxoft\Rebar\Db\Providers\Provider
	 */
	protected $writer = null;

	/**
	 * @var string Optional namespace for models.
	 */
	protected $modelNamespace;

	public function __construct(
		Providers\Provider $reader,
		Providers\Provider $writer,
		$modelNamespace = ''
	) {
		$this->reader = $reader;
		$this->writer = $writer;
		$this->modelNamespace = $modelNamespace;
	}

	/**
	 * Return a new (blank) model.
	 * @param $modelClass
	 * @return Model
	 */
	public function GetNew($modelClass) {
		return $this->GetOneById($modelClass, 0);
	}

	/**
	 * Return a Model of class $modelClass with ID property of $id.
	 * @param string $modelClass
	 * @param string $id
	 * @return Model
	 */
	public function GetOneById($modelClass, $id) {
		$modelClass = (strstr($modelClass, $this->modelNamespace)) ?
			$modelClass :
			$this->modelNamespace.$modelClass;
		return new $modelClass($this, $id);
	}

	/**
	 * Return a single Model of class $modelClass selected with $where.
	 * @param string $modelClass
	 * @param string $where
	 * @return Model
	 */
	public function GetOneWhere($modelClass, $where) {
		$modelClass = (strstr($modelClass, $this->modelNamespace)) ?
			$modelClass :
			$this->modelNamespace.$modelClass;
		$model = new $modelClass($this);
		$modelSet = $model->GetAll($where, '', 1, 1);
		return $modelSet[0];
	}

	/**
	 * Return an array of Model objects of type $modelClass selected with $filter, sorted by $sort,
	 * and limited to page $page where pages are $pageSize long.
	 * @param string $modelClass
	 * @param string $filter
	 * @param string $sort
	 * @param int $page
	 * @param int $pageSize
	 * @return array Model
	 */
	public function GetSet($modelClass, $filter = '', $sort = '', $page = 1, $pageSize = 0) {
		$modelClass = (strstr($modelClass, $this->modelNamespace)) ?
			$modelClass :
			$this->modelNamespace.$modelClass;
		$model = new $modelClass($this);
		return $model->GetAll($filter, $sort, $page, $pageSize);
	}

	/**
	 * Given the name of a model and an array of data rows, will return a set of objects
	 * populated with the data set. Used for easily retrieving a set of objects from the
	 * results returned by a custom query.
	 * @param $modelClass
	 * @param array $dataSet
	 * @return Model[]
	 */
	public function GetSetFromDataSet($modelClass, array $dataSet) {
		$modelClass = (strstr($modelClass, $this->modelNamespace)) ?
			$modelClass :
			$this->modelNamespace.$modelClass;
		/** @var Model $model */
		$model = new $modelClass($this);
		return $model->GetObjectSet($dataSet);
	}

	/**
	 * Delete the Model of type $modelClass with ID property of $id.
	 * @param string $modelClass
	 * @param mixed $id
	 */
	public function DeleteById($modelClass, $id) {
		$modelClass = (strstr($modelClass, $this->modelNamespace)) ?
			$modelClass :
			$this->modelNamespace.$modelClass;
		$model = new $modelClass($this);
		$model->Delete($id);
	}

	public function __get($var) {
		switch ($var) {
			case 'Reader':
				$rtn = $this->reader;
				break;
			case 'Writer':
				$rtn = $this->writer;
				break;
			default:
				throw new \InvalidArgumentException(sprintf('Cannot get property "%s"', $var));
				break;
		}
		return $rtn;
	}
}