<?php

namespace Fluxoft\Rebar\Rest;

use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Fluxoft\Rebar\Auth\Db\User;
use Fluxoft\Rebar\Db\Exceptions\InvalidModelException;
use Fluxoft\Rebar\Db\Mapper;
use Fluxoft\Rebar\Http\Request;
use Psr\Log\LoggerInterface;

/**
 * Class DataRepository
 * @package Fluxoft\Rebar\Rest
 */
class DataRepository implements RepositoryInterface {
	/** @var Mapper */
	protected $mapper;
	/** @var LoggerInterface */
	protected $logger;
	/** @var User */
	protected $authUser;

	protected $authUserFilter     = false;
	protected $authUserIDProperty = 'UserID';

	/**
	 * If specified for a repository, this is the default page size
	 * @var null|int
	 */
	protected $defaultPageSize = null;

	/**
	 * @param Mapper $mapper
	 * @param LoggerInterface $logger
	 * @param User $authUser
	 */
	public function __construct(Mapper $mapper, LoggerInterface $logger = null, User $authUser = null) {
		$this->mapper   = $mapper;
		$this->logger   = $logger;
		$this->authUser = $authUser;
	}

	/**
	 * @param Request $request
	 * @param array $params
	 * @return Reply
	 */
	public function Get(Request $request, $params = []) {
		/**
		 * GET /{item}                 <- retrieve a set
		 * GET /{item}?page={page}     <- retrieve page {page} of results
		 * GET /{item}/{id}            <- retrieve {item} with id {id}
		 * GET /{item}/{id}/{children} <- retrieve the children of {item} with id {id}
		 *     ** the above only works on Mappers which have a Get{children} method accepting {id} as an argument
		 */
		$page     = 1;
		$pageSize = null;
		$get      = $request->Get();
		if (isset($this->defaultPageSize) && is_int($this->defaultPageSize)) {
			$pageSize = $this->defaultPageSize;
		}
		if (isset($get['page'])) {
			if (is_array($get['page'])) {
				if (isset($get['page']['number']) && is_numeric($get['page']['number'])) {
					$page = (int) $get['page']['number'];
				}
				if (isset($get['page']['size']) && is_numeric($get['page']['size'])) {
					$pageSize = (int) $get['page']['size'];
				}
			} elseif (is_numeric($get['page'])) {
				$page = (int) $get['page'];
			}
			if (isset($get['pageSize']) && is_numeric($get['pageSize'])) {
				$pageSize = (int) $get['pageSize'];
			}
		}

		unset($get['page']);
		unset($get['callback']);

		if ($this->authUserFilter && !isset($this->authUser)) {
			return new Reply(403, [], [], new Error(403, 'Must be logged in to access this resource.'));
		}

		$reply = new Reply();
		switch (count($params)) {
			case 0:
				if ($this->authUserFilter && isset($this->authUser)) {
					$get[$this->authUserIDProperty] = $this->authUser->GetID();
				}

				$order = [];
				if (isset($get['order'])) {
					if (is_array($get['order'])) {
						$order = $get['order'];
					} else {
						$order = [$get['order']];
					}
					unset($get['order']);
				}
				$set   = $this->mapper->GetSetWhere($get, $order, $page, $pageSize);
				$count = $this->mapper->CountWhere($get);
				$pages = (isset($pageSize) && $pageSize > 0) ? ceil($count/$pageSize) : 1;

				$reply->Data = $set;
				$reply->Meta = [
					'page' => $page,
					'pages' => $pages,
					'count' => $count
				];
				break;
			case 1:
				$item = $this->mapper->GetOneById($params[0]);
				if (!isset($item)) {
					$reply->Status = 404;
					$reply->Error  = new Error(404, 'The requested item could not be found.');
				} else {
					if ($this->authUserFilter &&
						$item->{$this->authUserIDProperty} !== $this->authUser->GetID()
					) {
						$reply->Status = 404;
						$reply->Error  = new Error(404, 'The requested item could not be found.');
					} else {
						$reply->Data = $item;
					}
				}
				break;
			case 2:
				$id         = $params[0];
				$subsetName = $params[1];
				$getter     = 'Get'.ucwords($subsetName);
				$counter    = 'Count'.ucwords($subsetName);
				if (!method_exists($this->mapper, $getter)) {
					$reply->Status = 404;
					$reply->Error  = new Error(404, sprintf('"%s" not found.', $subsetName));
				} elseif (!method_exists($this->mapper, $counter)) {
					$reply->Status = 500;
					$reply->Error  = new Error(500, sprintf(
						'Counter method "%s" not found in mapper "%s"',
						$counter,
						get_class($this->mapper)
					));
				} else {
					if ($this->authUserFilter) {
						$parent = $this->mapper->GetOneById($id);

						if (!isset($parent) ||
							($parent->{$this->authUserIDProperty} !== $this->authUser->GetID())
						) {
							$reply->Status = 404;
							$reply->Error  = new Error(404, 'The requested item could not be found.');
						} else {
							$subset = $this->mapper->$getter($parent->GetID(), $page, $pageSize);
							$count  = $this->mapper->$counter($parent->GetID());
							$pages  = (isset($pageSize) && $pageSize > 0) ? ceil($count/$pageSize) : 1;

							$reply->Data = $subset;
							$reply->Meta = [
								'page' => $page,
								'pages' => $pages,
								'count' => $count
							];
						}
					} else {
						$subset = $this->mapper->$getter($id, $page, $pageSize);
						if (isset($subset)) {
							$count = $this->mapper->$counter($id);
							$pages = (isset($pageSize) && $pageSize > 0) ? ceil($count/$pageSize) : 1;

							$reply->Data = $subset;
							$reply->Meta = [
								'page' => $page,
								'pages' => $pages,
								'count' => $count
							];
						} else {
							$reply->Status = 404;
							$reply->Error  = new Error(404, 'The subset returned a null result.');
						}
					}
				}
				break;
			default:
				$reply->Status = 400;
				$reply->Error  = new Error(400, 'Too many parameters in URL.');
				break;
		}
		return $reply;
	}

	/**
	 * POST /{item}/{id} <- CREATE an {item} with ID {id} using POST/PUT params
	 * @param Request $request
	 * @param array $params
	 * @return Reply
	 */
	public function Post(Request $request, $params = []) {
		// $params is unused in this implementation
		$params = null;

		$model = $this->getPostData($request);

		if ($this->authUserFilter) {
			if (!isset($this->authUser)) {
				return new Reply(403, [], [], new Error(403, 'Must be logged in to access this resource.'));
			} else {
				// Just change the $post's UserID to the user's. This will let the attacker add
				// something, but to his own account, not someone else's. This actually has the
				// somewhat dubious side effect of allowing someone to add something without
				// the need to pass in their UserID.
				$model[$this->authUserIDProperty] = $this->authUser->GetID();
			}
		}
		$new = $this->mapper->GetNew();
		foreach ($model as $key => $value) {
			$new->$key = $value;
		}

		$reply = new Reply();
		try {
			$this->mapper->Save($new);
			$reply->Status = 201;
			$reply->Data   = $new;
		} catch (InvalidModelException $e) {
			$reply->Status = 422;
			$reply->Error  = new Error(
				422,
				$e->getMessage(),
				['invalidProperties' => $new->GetValidationErrors()]
			);
		} catch (\InvalidArgumentException $e) {
			$reply->Status = 422;
			$reply->Error  = new Error(422, $e->getMessage());
		} catch (UniqueConstraintViolationException $e) {
			$reply->Status = 409;
			$reply->Error  = new Error(409, 'Object already exists');
		} catch (DBALException $e) {
			$this->log('error', $e->getMessage());
			$reply->Status = 500;
			$reply->Error  = new Error(
				500,
				'Database error. Please try again later.',
				[
					'Code' => $e->getCode(),
					'Message' => $e->getMessage(),
					'Line' => $e->getLine(),
					'File' => $e->getFile(),
					'Trace' => $e->getTraceAsString()
				]
			);
		} catch (\Exception $e) {
			$reply->Status = 500;
			$reply->Error  = new Error(500, $e->getMessage());
		}
		return $reply;
	}

	/**
	 * PUT /{item}/{id} <- UPDATE an {item} with ID {id} using POST/PUT params
	 * @param Request $request
	 * @param array $params
	 * @return Reply
	 */
	public function Put(Request $request, $params = []) {
		if (empty($params)) {
			return new Reply(422, [], [], new Error(422, 'You must specify an ID in order to update.'));
		} else {
			if ($this->authUserFilter && !isset($this->authUser)) {
				return new Reply(403, [], [], new Error(403, 'Must be logged in to access this resource.'));
			}

			$id = $params[0];
			/** @var \Fluxoft\Rebar\Db\Model $update */
			$update = $this->mapper->GetOneById($id);
			if ($this->authUserFilter) {
				if ($update->{$this->authUserIDProperty} !== $this->authUser->GetID()) {
					$update = false;
				}
			}
			if (!isset($update)) {
				return new Reply(404, [], [], new Error(404, 'The object to be updated was not found.'));
			} else {
				$errors = [];
				$model  = $this->getPutData($request);

				foreach ($model as $key => $value) {
					try {
						$update->$key = $value;
					} catch (\InvalidArgumentException $e) {
						$errors[] = $e->getMessage();
					}
				}
				if (empty($errors)) {
					try {
						$this->mapper->Save($update);
						return new Reply(200, $update);
					} catch (InvalidModelException $e) {
						return new Reply(
							422,
							[],
							[],
							new Error(422, $e->getMessage(), ['invalidProperties' => $update->GetValidationErrors()])
						);
					}
				} else {
					return new Reply(422, [], [], new Error(422, 'Errors saving properties', ['errors' => $errors]));
				}
			}
		}
	}

	/**
	 * The repository handles PUT and PATCH requests the exact same way, so this should just call Put
	 * @param Request $request
	 * @param array $params
	 * @return Reply
	 */
	public function Patch(Request $request, $params = []) {
		return $this->Put($request, $params);
	}

	/**
	 * @param Request $request
	 * @param array $params
	 * @return Reply
	 */
	public function Delete(Request $request, $params = []) {
		// $request is unused in this implementation
		$request = null;

		if (empty($params)) {
			// cannot delete if we don't have an id
			return new Reply(422, [], [], new Error(422, 'ID is required for DELETE operation.'));
		} else {
			if ($this->authUserFilter && !isset($this->authUser)) {
				return new Reply(403, [], [], new Error(403, 'Must be logged in to access this resource.'));
			}
			$id = $params[0];

			$delete = $this->mapper->GetOneById($id);
			if ($this->authUserFilter) {
				if ($delete->{$this->authUserIDProperty} !== $this->authUser->GetID()) {
					$delete = null;
				}
			}
			if (!isset($delete)) {
				return new Reply(403, [], [], new Error(403, 'Must be logged in to access this resource.'));
			} else {
				$this->mapper->Delete($delete);
				return new Reply(204, ['success' => 'The item was deleted.']);
			}
		}
	}

	private $postData = null;

	/**
	 * Will return the request's data as an array from whatever source it can find.
	 * Can be called in child classes to modify the contents of the data before saving.
	 * @param Request $request
	 * @return array
	 */
	protected function getPostData(Request $request) {
		if (!isset($this->postData)) {
			$body = $request->Body;
			/** @var array $postVars */
			$postVars = $request->Post();

			if (isset($postVars['model'])) {
				$this->postData = json_decode($postVars['model'], true);
			} elseif (!empty($postVars)) {
				$this->postData = $postVars;
			} elseif (strlen($body) > 0) {
				$this->postData = json_decode($body, true);
			} else {
				$this->postData = [];
			}
		}
		return $this->postData;
	}
	protected function setPostData(array $postData) {
		$this->postData = $postData;
	}

	private $putData = null;

	/**
	 * Will return the request's data as an array from whatever source it can find.
	 * Can be called in child classes to modify the contents of the data before saving.
	 * @param Request $request
	 * @return array
	 */
	protected function getPutData(Request $request) {
		if (!isset($this->putData)) {
			$body    = $request->Body;
			$putVars = $request->Put();

			if (isset($putVars['model'])) {
				$this->putData = json_decode($putVars['model'], true);
			} elseif (!empty($putVars)) {
				$this->putData = $putVars;
			} elseif (strlen($body) > 0) {
				$this->putData = json_decode($body, true);
			} else {
				$this->putData = [];
			}
		}
		return $this->putData;
	}
	protected function setPutData(array $putData) {
		$this->putData = $putData;
	}

	protected function log($type, $message) {
		if (isset($this->logger)) {
			$this->logger->$type($message);
		}
	}
}
