<?php
/**
 * Manages reading and saving associated with CRUD views
 *
 * @package app.Controller.Component
 **/

App::uses('Component', 'Controller');
App::uses('Hash', 'Utility');
App::uses('Inflector', 'Utility');


class CrudComponent extends Component {

/**
 * Other components used by CrudComponent
 * 
 * @var array
 **/
	public $components = array('Session');

/**
 * Reference to the instantiating controller object
 *
 * @var Controller
 **/
	public $controller;

/**
 * Request object
 *
 * @var CakeRequest
 */
	public $request;

/**
 * Response object
 *
 * @var CakeResponse
 */
	public $response;

/**
 * Method list for bound controller.
 *
 * @var array
 */
	protected $_methods = array();

/**
 * The referring URL
 *
 * @var string
 **/
	protected $_referer = null;

/**
 * The reffering URL in array format
 * @var array
 **/
 	protected $_refererParts = array();

/**
 * Variables to pass back to the controller
 *
 * @var array
 **/
	protected $_vars = array();

/**
 * The class name of the model used with the component
 *
 * @var string
 **/
	public $modelClass = null;

/**
 * The Model object working with the component
 *
 * @var Model
 **/
	public $Model = null;


const ERROR_CLASS = 'alert-danger';
const WARNING_CLASS = 'alert-warning';
const INFO_CLASS = 'alert-info';
const SUCCESS_CLASS = 'alert-success';

/**
 * Initializes AuthComponent for use in the controller.
 *
 * @param Controller $controller A reference to the instantiating controller object
 * @return void
 */
	public function initialize(Controller $controller) {
		$this->Controller = $controller;

		$this->request = $controller->request;
		$this->response = $controller->response;
		$this->_methods = $controller->methods;
		$this->_referer = $controller->referer();
		$this->_refererParts = Router::parse($controller->referer(null, true));

		$this->setModelClass($controller->modelClass);
	}


/**
 * Sets the model to be used with the component
 *
 * @param string $modelClass Class name of the model
 * @return void
 **/
	public function setModelClass($modelClass) {
		$this->modelClass = $modelClass;
		$this->Model = ClassRegistry::init($modelClass, true);
	}

/**
 * Creates a new model entry. Usually used with the "add" controller method
 *
 * @param array $options Save options
 * @return void;
 **/
	public function create($options = array()) {
		$result = $this->save($options);
		if ($result === null) {
			if (!empty($options['default'])) {
				$this->request->data = $options['default'];
			}
		}
		$this->setFormElements();
		$this->formRender(isset($options['view']) ? $options['view'] : null);
		return $result;
	}

/**
 * Reads a single model row. Usually used with the "view" controller method
 *
 * @param int $id The model id to be read
 * @param array $query Any additional find query options
 * @return array|bool The value of the returned set if found, false if not;
 **/
 	public function read($id, $query = array()) {
 		$query['conditions'][$this->Model->escapeField()] = $id;
 		$result = $this->Model->find('first', $query);

 		// If the result is not found, redirect to the referer
 		if (empty($result)) {
 			$this->setMessage(sprintf('Could not find %s id #%s', Inflector::humanize($this->Model->alias), $id), self::ERROR_CLASS, true);
 			debug(Debugger::trace());
 		}

 		// Passes the result to the controller
 		$this->Controller->set(Inflector::variable($this->modelClass), $result);
 		return $result;
	}

/**
 * Updates a single model entry. Usually used with the "edit" controller method.
 *
 * @param int $id The model id to be updated
 * @param array $options Save options
 * @return void;
 **/
	public function update($id = null, $options = array()) {
		$result = $this->save($options);
		if ($result === null) {
			// Sets default form data
			$result = $this->read($id, !empty($options['query']) ? $options['query'] : null);
			$this->request->data = $result;
		} else {
			$this->read($this->Model->id);			
		}
		$this->setFormElements($id);
		$this->formRender(isset($options['view']) ? $options['view'] : null);
	}

/**
 * Deletes a model entry. Usually used with the "delete" controller method.
 *
 * @param int $id The model id to be deleted
 * @return void;
 **/
	public function delete($id = null, $options = array()) {
		$controller = Inflector::tableize($this->modelClass);
		$defaultUrl = compact('controller') + array('action' => 'index');
		$referer = $this->_refererParts;

		// If the referring URL is from the associating view of the same ID, redirect to the index. 
		// Otherwise redirect to referer
		$successRedirect = true;
		if (
			is_array($referer) && 
			(isset($referer['controller']) && $referer['controller'] == $controller) && 
			(isset($referer['action']) && $referer['action'] == 'view') &&
			(isset($referer['pass'][0]) && $referer['pass'][0] == $id)
		) {
			$successRedirect = $defaultUrl;
		}

		$result = $this->Model->read(array($this->Model->primaryKey, $this->Model->displayField), $id);

		$default = array(
			'success' => array(
				'message' => 'Successfully deleted id #' . $id,
				'class' => self::SUCCESS_CLASS,
				'redirect' => $successRedirect,
			),
			'fail' => array(
				'message' => 'There was an error deleting id #' . $id,
				'class' => self::ERROR_CLASS,
				'redirect' => true,
			),
			'notFound' => array(
				'message' => 'Please select an id',
				'class' => self::INFO_CLASS,
				'redirect' => true,
			)
		);

		if (!empty($result) && !empty($result[$this->Model->alias][$this->Model->displayField])) {
			$default['success']['message'] = sprintf('Successfully deleted %s "%s"', 
				$this->Model->alias,
				$result[$this->Model->alias][$this->Model->displayField]
			);
		}

		$options = Hash::merge($default, $options);

		if (empty($id) || empty($result)) {
			extract($options['notFound']);
		} else {
			if ($this->Model->delete($id)) {
				extract($options['success']);
			} else {
				extract($options['fail']);
			}
		}

		$this->setMessage($message, $class, $redirect);
	}

/**
 * Saves the data generated by the CRUD methods and handles the wrapup
 *
 * ### Options
 * 
 *	- `save` Any save options you want to pass to the actual save method
 * 	- `success` Wrapup information if the save was successful
 * 	- `fail` Wrapup information if the save fails
 *
 * @param array $options An array of save attributes
 * @return bool True if success, false on failure;
 **/
	public function save($options = array()) {
		$options = Hash::merge(array(
			// Model save options
			'save' => array(),
			// Wrapup instructions if save was successful
			'success' => array(
				'message' => 'Successfully saved',
				'class' => self::SUCCESS_CLASS,
				'redirect' => array(
					'controller' => Inflector::tableize($this->modelClass),
					'action' => 'view',
					'__ID__',
				)
			),
			// Wrapup instructions if save fails
			'fail' => array(
				'message' => 'There was an error saving',
				'class' => self::ERROR_CLASS,
				'redirect' => false,
			),
		), $options);

		$success = null;

		// Checks for passed data
		if (!empty($this->request->data)) {
			$data =& $this->request->data;
			$oData = $data;	//Stores data

			$success = false;

			// Before Save
			if (($data = $this->beforeSave($data, $options['save'])) === false) {
				$data = $oData;
				$result = false;
			} else {
				// Saves data
				if (!empty($data[$this->modelClass]) && count($data[$this->modelClass]) == 1) {
					if (empty($data[$this->modelClass][0])) {
						$result = $this->Model->save($data[$this->modelClass], $options['save']);
					} else {
						$result = $this->Model->saveAll($data[$this->modelClass], $options['save']);
					}
				} else {
					$result = $this->Model->saveAll($data, $options['save']);
				}

				if (!$success) {
					$validationErrors = $this->_getValidationErrors();
				}

				$success = !empty($result);
				$created = !empty($data[$this->modelClass]) ? empty($data[$this->modelClass][$this->Model->primaryKey]) : empty($data[$this->Model->primaryKey]);
				
				// After Save
				$this->afterSave($success, $created);
			}

			// Wraps up after save and looks for messages or redirecting based on success
			$return = $success ? $options['success'] : $options['fail'];
			extract($return);
			if (is_array($redirect)) {
				foreach ($redirect as &$v) {
					if ($v == '__ID__') {
						$v = $this->Model->id;
					}
				}
			}
			if (!empty($validationErrors)) {
				$message .= $validationErrors;
			}
			$this->setMessage($message, $class, $redirect);
		}
		return $success;
	}

	private function _getValidationErrors() {
		$models = ClassRegistry::keys();
		$validationErrors = array();
		foreach ($models as $currentModel) {
			$currentObject = ClassRegistry::getObject($currentModel);
			if ($currentObject instanceof Model && !empty($currentObject->validationErrors)) {
				$validationErrors = $this->_flattenArray($currentObject->validationErrors, $validationErrors);
			}
		}
		if (empty($validationErrors)) {
			return '';
		}
		return "<ul><li>" . implode('</li><li>', $validationErrors) . "</li></ul>";
	}

	private function _ulArray($array, $recursive = false, $flatten = false) {
		$out = '';
		foreach ($array as $row) {
			if (is_array($row)) {
				$row = $this->_ulArray($row, true, $flatten);
			}
			$out .= "\t<li>$row</li>\n";
		}
		if ($recursive && $flatten) {
			return $out;
		} else {
			return "<ul>\n$out</ul>\n";
		}
	}

	private function _flattenArray($array, $result = array()) {
		foreach ($array as $row) {
			if (is_array($row)) {
				$result = $this->_flattenArray($row, $result);
			} else {
				$result[] = $row;
			}
		}
		return $result;
	}

/**
 * Looks in the requested data for an HABTM list of ids
 *
 * A FormElements helper function to find a list of passed values with an HABTM relationship to the modelClass
 *
 * @param string $modelName The name of the model you're searching for
 * @return array A list of ($id => $title) values
 **/
	public function findHabtmList($modelName) {
		$data =& $this->controller->request->data;
		if (!empty($data[$modelName][0])) {
			// Initially pulled from the database
			$extract = $modelName . '.{n}.id';
		} else if (!empty($data[$modelName][$modelName])) {
			// Passed as a form
			$extract = $modelName . '.' . $modelName . '.{n}';
		} else {
			$extract = false;
		}
		if ($extract) {
			$Model = ClassRegistry::init($modelName);
			return $Model->find('list', [
				'conditions' => [$Model->escapeField() => Hash::extract($data, $extract)]
			]);
		}
		return null;
	}

/**
 * Called before the CrudComponent save method. It can adjust the data being passed, or validate it against a Controller method
 *
 * @param array $data The request data
 * @param array $options Save options
 * @return array|boolean Returns the adjusted data if successful, false on failure
 **/
	protected function beforeSave($data, $options = array()) {
		$return = $this->controllerMethod('_beforeCrudSave', array($data, $options));
		if ($return !== null) {
			$data = $return;
		}
		return $data;
	}

/** 
 * Called after the CrudComponent save method.
 *
 * @param bool $success Whether or not the save was successful
 * @param bool $created Whether or not a new model entry was created
 * @return void;
 **/
	protected function afterSave($success, $created) {
		if ($success) {
			$this->controllerMethod('_afterCrudSave', $created);
		} else {
			$this->controllerMethod('_afterCrudSaveFail');
		}
	}

/**
 * Sets alert messages, and redirects if necessary
 *
 * @param string $message The text to be displayed
 * @param array|string|boolean $redirect Where to redirect after the message is displayed
 * 			- If true it will redirect to referer
 *			- If false, it will not redirect
 *			- If string or array, it will redirect to the new URL
 * @return void;
 **/
	protected function setMessage($message, $messageClass = null, $redirect = false) {
 		$element = 'default';
 		$key = 'flash';
 		$params = array('class' => 'alert');
 		
 		if ($messageClass === true) {
 			$messageClass = self::SUCCESS_CLASS;
 		} else if ($messageClass === false) {
 			$messageClass = self::ERROR_CLASS;
 		} else if ($messageClass === null) {
 			$messageClass = self::INFO_CLASS;
 		}
		$params['class'] .= ' ' . $messageClass;

		$this->Session->setFlash($message, $element, $params, $key);
		$this->redirect($redirect);
	}

/**
 * Sets a url to be redirected
 * 
 * @param array|string|boolean $redirect Where to redirect after the message is displayed
 * 			- If true it will redirect to referer
 *			- If false, it will not redirect
 *			- If string or array, it will redirect to the new URL
 * @return void;
 **/
 	protected function redirect($redirect = true) {
 		if ($redirect !== false) {
			if ($redirect === true) {
				$redirect = $this->_referer;
			}
			$this->Controller->redirect($redirect);
		}
	}

/** 
 * Sets form elements to be displayed in the form
 *
 * @param int $id The id of the model if present
 * @return void;
 **/
	protected function setFormElements($id = null) {
		$this->controllerMethod('_setFormElements', $id);
	}

/**
 * Calls a controller method if it exists
 * 
 * @param string $method Method name
 * @param array $args Arguments to pass to the method
 * @return mixed Method's return if it exists, null if it doesn't exist
 **/
	protected function controllerMethod($method, $args = null) {
		if (method_exists($this->Controller, $method)) {
			if (empty($args) || !is_array($args)) {
				$args = array($args);
			}
			return call_user_func_array(array($this->Controller, $method), $args);
		} else {
			return null;
		}
	}

/**
 * Render a view or element and skip the method view
 *
 * @param string $view The view you'd like to render
 * 		- If no view is present, it will default to /Elements/CONTROLLER NAME/form.ctp
 * @return void
 **/
	protected function formRender($view = null) {
		if ($view !== false) {
			if (empty($view) || $view === true) {
				$view = DS . 'Elements' . DS . Inflector::tableize($this->modelClass) . DS . 'form';
				if (!empty($this->Model->plugin)) {
					$view = $this->Model->plugin . '.' . $view;
				}
			}

			list($plugin, $view) = pluginSplit($view);

			if (!empty($plugin)) {
				$path = APP . 'Plugin' . DS . 'View' . DS . $this->Model->plugin . DS . $view . '.ctp';
			} else {
				$path = APP . 'View' . DS . $view . '.ctp';
			}

			if (is_file($path)) {
				return $this->Controller->render($view);
			}
		}
		return null;
	}
}