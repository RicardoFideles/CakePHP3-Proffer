<?php
/**
 * Proffer
 * An upload behavior plugin for CakePHP 3
 *
 * @author David Yell <neon1024@gmail.com>
 */

namespace Proffer\Model\Behavior;

use ArrayObject;
use Cake\Event\Event;
use Cake\Network\Exception\BadRequestException;
use Cake\ORM\Behavior;
use Cake\ORM\Entity;
use Cake\ORM\Table;
use Cake\Utility\String;
use Exception;
use Proffer\Event\ImageTransform;

/**
 * Proffer behavior
 */
class ProfferBehavior extends Behavior {

/**
 * Default configuration.
 *
 * @var array
 */
	protected $_defaultConfig = [];

/**
 * Initialize the behavior
 *
 * @param array $config Array of pass configuration
 * @return void
 */
	public function initialize(array $config) {
		$imageTransform = new ImageTransform();
		$this->_table->eventManager()->attach($imageTransform);
	}

/**
 * beforeValidate method
 *
 * @param Event $event The event
 * @param Entity $entity The current entity
 * @param ArrayObject $options Array of options
 * @return true
 */
	public function beforeValidate(Event $event, Entity $entity, ArrayObject $options) {
		foreach ($this->config() as $field => $settings) {
			if ($this->_table->validator()->isEmptyAllowed($field, false) && $entity->get($field)['error'] === UPLOAD_ERR_NO_FILE) {
				$entity->__unset($field);
			}
		}

		return true;
	}

/**
 * beforeSave method
 *
 * @param Event $event The event
 * @param Entity $entity The entity
 * @param ArrayObject $options Array of options
 * @return true
 * @throws Cake\Network\Exception\BadRequestException
 * @throws Exception
 */
	public function beforeSave(Event $event, Entity $entity, ArrayObject $options) {
		foreach ($this->config() as $field => $settings) {
			if ($entity->has($field) && is_array($entity->get($field)) && $entity->get($field)['error'] === UPLOAD_ERR_OK) {

				if (!$this->isUploadedFile($entity->get($field)['tmp_name'])) {
					throw new BadRequestException('File must be uploaded using HTTP post.');
				}

				$path = $this->buildPath($this->_table, $entity, $field);

				if ($this->moveUploadedFile($entity->get($field)['tmp_name'], $path['full'])) {
					$entity->set($field, $entity->get($field)['name']);
					$entity->set($settings['dir'], $path['parts']['seed']);

					// Don't generate thumbnails for non-images
					if (getimagesize($path['full']) !== false) {
						$this->makeThumbs($field, $path);
					}
				} else {
					throw new Exception('Cannot move file');
				}
			}
		}

		return true;
	}

/**
 * Generate the defined thumbnails
 *
 * @param string $field The name of the upload field
 * @param string $path The path array
 * @return void
 */
	protected function makeThumbs($field, $path) {
		foreach ($this->config($field)['thumbnailSizes'] as $prefix => $dimensions) {

			$eventParams = ['path' => $path, 'dimensions' => $dimensions, 'thumbnailMethod' => null];

			if (isset($this->config($field)['thumbnailMethod'])) {
				$params['thumbnailMethod'] = $this->config($field)['thumbnailMethod'];
			}

			// Event listener handles generation
			$event = new Event('Proffer.beforeThumbs', $this->_table, $eventParams);

			$this->_table->eventManager()->dispatch($event);
			if (!empty($event->result)) {
				$image = $event->result;
			}

			$event = new Event('Proffer.afterThumbs', $this->_table, [
				'image' => $image,
				'path' => $path,
				'prefix' => $prefix
			]);

			$this->_table->eventManager()->dispatch($event);
			if (!empty($event->result)) {
				$image = $event->result;
			}
		}
	}

/**
 * Build a path to upload a file to. Both parts and full path
 *
 * @param Table $table The table
 * @param Entity $entity The entity
 * @param string $field The upload field name
 * @return array
 */
	protected function buildPath(Table $table, Entity $entity, $field) {
		$path['root'] = WWW_ROOT . 'files';
		$path['table'] = strtolower($table->alias());

		$dir = $entity->get($this->config($field)['dir']);
		if (!empty($dir)) {
			$path['seed'] = $entity->get($this->config($field)['dir']);
		} else {
			$path['seed'] = String::uuid();
		}

		$path['name'] = $entity->get($field)['name'];

		$fullPath = implode(DS, $path);

		if (!file_exists($path['root'] . DS . $path['table'] . DS . $path['seed'] . DS)) {
			mkdir($path['root'] . DS . $path['table'] . DS . $path['seed'] . DS, 0777, true);
		}

		return ['full' => $fullPath, 'parts' => $path];
	}

/**
 * Wrapper method for is_uploaded_file so that we can test
 *
 * @param string $file The tmp_name path to the uploaded file
 * @return bool
 */
	protected function isUploadedFile($file) {
		return is_uploaded_file($file);
	}

/**
 * Wrapper method for move_uploaded_file so that we can test
 *
 * @param string $file Path to the uploaded file
 * @param string $destination The destination file name
 * @return bool
 */
	protected function moveUploadedFile($file, $destination) {
		return move_uploaded_file($file, $destination);
	}
}
