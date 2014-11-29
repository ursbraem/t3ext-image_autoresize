<?php
namespace Causal\ImageAutoresize\Utility;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2013-2014 Xavier Perseguers <xavier@causal.ch>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *  A copy is found in the textfile GPL.txt and important notices to the license
 *  from the author is found in LICENSE.txt distributed with these scripts.
 *
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * This is a FAL-manipulation utility.
 *
 * @category    Utility
 * @package     TYPO3
 * @subpackage  tx_imageautoresize
 * @author      Xavier Perseguers <xavier@causal.ch>
 * @license     http://www.gnu.org/copyleft/gpl.html
 */
class FAL {

	/** @var array */
	static protected $reflectedClasses = array();

	/**
	 * Creates/updates the index entry for a given file.
	 *
	 * @param \TYPO3\CMS\Core\Resource\File $file
	 * @param string $origFileName
	 * @param string $newFileName
	 * @param integer $width
	 * @param integer $height
	 * @param array $metadata EXIF metadata
	 * @return void
	 */
	static public function indexFile(\TYPO3\CMS\Core\Resource\File $file = NULL, $origFileName, $newFileName, $width, $height, array $metadata = array()) {
		if ($file === NULL) {
			$file = static::findExistingFile($origFileName);
		}
		if ($file !== NULL) {
			static::updateIndex($file, $width, $height, $metadata);
		} else {
			static::createIndex($newFileName, $width, $height);
		}
	}

	/**
	 * Finds an existing file.
	 *
	 * @param string $fileName
	 * @return \TYPO3\CMS\Core\Resource\AbstractFile|NULL
	 */
	static protected function findExistingFile($fileName) {
		$file = NULL;
		$relativePath = substr(PathUtility::dirname($fileName), strlen(PATH_site));
		$resourceFactory = \TYPO3\CMS\Core\Resource\ResourceFactory::getInstance();
		$targetFolder = $resourceFactory->retrieveFileOrFolderObject($relativePath);

		$storageConfiguration = $targetFolder->getStorage()->getConfiguration();
		if (isset($storageConfiguration['basePath'])) {
			$basePath = rtrim($storageConfiguration['basePath'], '/') . '/';
			$basePath = GeneralUtility::getFileAbsFileName($basePath);
			$identifier = substr($fileName, strlen($basePath) - 1);

			$row = $GLOBALS['TYPO3_DB']->exec_SELECTgetSingleRow(
				'uid',
				'sys_file',
				'storage=' . intval($targetFolder->getStorage()->getUid()) .
					' AND identifier=' . $GLOBALS['TYPO3_DB']->fullQuoteStr($identifier, 'sys_file') .
					\TYPO3\CMS\Backend\Utility\BackendUtility::deleteClause('sys_file')
			);

			if (!empty($row['uid'])) {
				/** @var \TYPO3\CMS\Core\Resource\FileRepository $fileRepository */
				$fileRepository = GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Resource\\FileRepository');
				$file = $fileRepository->findByUid($row['uid']);
			}
		}

		return $file;
	}

	/**
	 * Updates the index entry for a given file.
	 *
	 * @param \TYPO3\CMS\Core\Resource\File $file
	 * @param integer $width
	 * @param integer $height
	 * @param array $metadata EXIF metadata
	 * @return void
	 */
	static protected function updateIndex(\TYPO3\CMS\Core\Resource\File $file = NULL, $width, $height, array $metadata = array()) {
		if (version_compare(TYPO3_version, '6.99.99', '<=')) {
			/** @var \TYPO3\CMS\Core\Resource\Service\IndexerService $indexerService */
			$indexerService = GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Resource\\Service\\IndexerService');
			$indexerService->indexFile($file);
		}

		if (count($metadata) > 0) {
			/** @var \TYPO3\CMS\Core\Resource\Index\MetaDataRepository $metadataRepository */
			$metadataRepository = GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Resource\\Index\\MetaDataRepository');
			// Will take care of creating the record if it does not exist yet
			$currentMetadata = $metadataRepository->findByFile($file);
			$newMetadata = array(
				'unit' => 'px',
			);
			$mapping = array(
				//'caption' => '',
				'color_space' => 'ColorSpace',
				'content_creation_date' => 'DateTimeOriginal',
				//'content_modification_time' => '',
				'creator' => 'IPTCCreator|Company',
				'creator_tool' => 'Model|Make|Software',
				'description' => 'ImageDescription',
				'keywords' => 'IPTCKeywords',
				'latitude' => 'GPSLatitudeDecimal',
				'longitude' => 'GPSLongitudeDecimal',
				'location_city' => 'IPTCCity',
				'location_country' => 'IPTCCountry',
				'location_region' => 'IPTCRegion',
				'note' => 'IPTCLocation',
				'publisher' => 'IPTCCredit',
				//'ranking' => '',
				'source' => 'IPTCSource',
				//'status' => '',
				'title' => 'IPTCTitle',
			);
			foreach ($mapping as $falKey => $metadataKeyMapping) {
				$metatadaKeys = explode('|', $metadataKeyMapping);
				foreach ($metatadaKeys as $metadataKey) {
					$value = NULL;
					if (isset($metadata[$metadataKey])) {
						$value = trim($metadata[$metadataKey]);
						if (ord($value) === 1) $value = NULL;
						switch ($metadataKey) {
							case 'ColorSpace':
								if ($value == 1) {
									$value = 'RGB';
								} else {
									// Unknown
									$value = NULL;
								}
							break;
							case 'DateTimeOriginal':
								$value = strtotime($value);
							break;
						}
					}
					if (!empty($value)) {
						$newMetadata[$falKey] = $value;
						break;
					}
				}
			}
			$metadataRepository->update($file->getUid(), $newMetadata);
		}
	}

	/**
	 * Creates the index entry for a given file.
	 *
	 * @param string $fileName
	 * @param integer $width
	 * @param integer $height
	 * @return void
	 */
	static protected function createIndex($fileName, $width, $height) {
		$relativePath = substr(PathUtility::dirname($fileName), strlen(PATH_site));
		$resourceFactory = \TYPO3\CMS\Core\Resource\ResourceFactory::getInstance();
		$targetFolder = $resourceFactory->retrieveFileOrFolderObject($relativePath);
		$targetFilename = PathUtility::basename($fileName);

		$storageConfiguration = $targetFolder->getStorage()->getConfiguration();
		if (!isset($storageConfiguration['basePath'])) {
			// Probably a file found in uploads/ or similar
			return;
		}
		$basePath = rtrim($storageConfiguration['basePath'], '/') . '/';
		$basePath = GeneralUtility::getFileAbsFileName($basePath);
		$identifier = substr($fileName, strlen($basePath) - 1);

		// TODO: possibly create file with nearly no info and populate them with
		// a call to $file->getStorage()->getFileInfo($file) instead of using $driver
		/** @var \TYPO3\CMS\Core\Resource\Driver\AbstractDriver $driver */
		$driver = static::accessProtectedProperty($targetFolder->getStorage(), 'driver');
		$fileInfo = $driver->getFileInfoByIdentifier($identifier);
		$file = $resourceFactory->createFileObject($fileInfo);

		/** @var \TYPO3\CMS\Core\Resource\FileRepository $fileRepository */
		$fileRepository = GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Resource\\FileRepository');
		$fileRepository->addToIndex($file);
	}

	/**
	 * Returns the value of a protected property.
	 *
	 * @param object $object
	 * @param string $propertyName
	 * @return mixed
	 */
	static protected function accessProtectedProperty($object, $propertyName) {
		$className = get_class($object);
		if (!isset(static::$reflectedClasses[$className])) {
			static::$reflectedClasses[$className] = new \ReflectionClass($className);
		}
		$class = static::$reflectedClasses[$className];
		$property = $class->getProperty($propertyName);
		$property->setAccessible(TRUE);

		return $property->getValue($object);
	}

}
