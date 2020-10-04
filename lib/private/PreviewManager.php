<?php
/**
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 *
 * @author Christoph Wurst <christoph@winzerhof-wurst.at>
 * @author Joas Schilling <coding@schilljs.com>
 * @author John Molakvoæ (skjnldsv) <skjnldsv@protonmail.com>
 * @author Julius Härtl <jus@bitgrid.net>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Olivier Paroz <github@oparoz.com>
 * @author Robin Appelman <robin@icewind.nl>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 * @author Sebastian Steinmetz <462714+steiny2k@users.noreply.github.com>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program. If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OC;

use OC\AppFramework\Bootstrap\Coordinator;
use OC\Preview\Generator;
use OC\Preview\GeneratorHelper;
use OCP\AppFramework\QueryException;
use OCP\Files\File;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IConfig;
use OCP\IPreview;
use OCP\IServerContainer;
use OCP\Preview\IProviderV2;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class PreviewManager implements IPreview {
	/** @var IConfig */
	protected $config;

	/** @var IRootFolder */
	protected $rootFolder;

	/** @var IAppData */
	protected $appData;

	/** @var EventDispatcherInterface */
	protected $eventDispatcher;

	/** @var Generator */
	private $generator;

	/** @var GeneratorHelper */
	private $helper;

	/** @var bool */
	protected $providerListDirty = false;

	/** @var bool */
	protected $registeredCoreProviders = false;

	/** @var array */
	protected $providers = [];

	/** @var array mime type => support status */
	protected $mimeTypeSupportMap = [];

	/** @var array */
	protected $defaultProviders;

	/** @var string */
	protected $userId;

	/** @var Coordinator */
	private $bootstrapCoordinator;

	/** @var IServerContainer */
	private $container;

	/**
	 * PreviewManager constructor.
	 *
	 * @param IConfig $config
	 * @param IRootFolder $rootFolder
	 * @param IAppData $appData
	 * @param EventDispatcherInterface $eventDispatcher
	 * @param string $userId
	 */
	public function __construct(IConfig $config,
								IRootFolder $rootFolder,
								IAppData $appData,
								EventDispatcherInterface $eventDispatcher,
								GeneratorHelper $helper,
								$userId,
								Coordinator $bootstrapCoordinator,
								IServerContainer $container) {
		$this->config = $config;
		$this->rootFolder = $rootFolder;
		$this->appData = $appData;
		$this->eventDispatcher = $eventDispatcher;
		$this->helper = $helper;
		$this->userId = $userId;
		$this->bootstrapCoordinator = $bootstrapCoordinator;
		$this->container = $container;
	}

	/**
	 * In order to improve lazy loading a closure can be registered which will be
	 * called in case preview providers are actually requested
	 *
	 * $callable has to return an instance of \OCP\Preview\IProvider or \OCP\Preview\IProviderV2
	 *
	 * @param string $mimeTypeRegex Regex with the mime types that are supported by this provider
	 * @param \Closure $callable
	 * @return void
	 */
	public function registerProvider($mimeTypeRegex, \Closure $callable) {
		if (!$this->config->getSystemValue('enable_previews', true)) {
			return;
		}

		if (!isset($this->providers[$mimeTypeRegex])) {
			$this->providers[$mimeTypeRegex] = [];
		}
		$this->providers[$mimeTypeRegex][] = $callable;
		$this->providerListDirty = true;
	}

	/**
	 * Get all providers
	 * @return array
	 */
	public function getProviders() {
		if (!$this->config->getSystemValue('enable_previews', true)) {
			return [];
		}

		$this->registerCoreProviders();
		$this->registerBootstrapProviders();
		if ($this->providerListDirty) {
			$keys = array_map('strlen', array_keys($this->providers));
			array_multisort($keys, SORT_DESC, $this->providers);
			$this->providerListDirty = false;
		}

		return $this->providers;
	}

	/**
	 * Does the manager have any providers
	 * @return bool
	 */
	public function hasProviders() {
		$this->registerCoreProviders();
		return !empty($this->providers);
	}

	private function getGenerator(): Generator {
		if ($this->generator === null) {
			$this->generator = new Generator(
				$this->config,
				$this,
				$this->appData,
				new GeneratorHelper(
					$this->rootFolder,
					$this->config
				),
				$this->eventDispatcher
			);
		}
		return $this->generator;
	}

	/**
	 * Returns a preview of a file
	 *
	 * The cache is searched first and if nothing usable was found then a preview is
	 * generated by one of the providers
	 *
	 * @param File $file
	 * @param int $width
	 * @param int $height
	 * @param bool $crop
	 * @param string $mode
	 * @param string $mimeType
	 * @return ISimpleFile
	 * @throws NotFoundException
	 * @throws \InvalidArgumentException if the preview would be invalid (in case the original image is invalid)
	 * @since 11.0.0 - \InvalidArgumentException was added in 12.0.0
	 */
	public function getPreview(File $file, $width = -1, $height = -1, $crop = false, $mode = IPreview::MODE_FILL, $mimeType = null) {
		return $this->getGenerator()->getPreview($file, $width, $height, $crop, $mode, $mimeType);
	}

	/**
	 * Generates previews of a file
	 *
	 * @param File $file
	 * @param array $specifications
	 * @param string $mimeType
	 * @return ISimpleFile the last preview that was generated
	 * @throws NotFoundException
	 * @throws \InvalidArgumentException if the preview would be invalid (in case the original image is invalid)
	 * @since 19.0.0
	 */
	public function generatePreviews(File $file, array $specifications, $mimeType = null) {
		return $this->getGenerator()->generatePreviews($file, $specifications, $mimeType);
	}

	/**
	 * returns true if the passed mime type is supported
	 *
	 * @param string $mimeType
	 * @return boolean
	 */
	public function isMimeSupported($mimeType = '*') {
		if (!$this->config->getSystemValue('enable_previews', true)) {
			return false;
		}

		if (isset($this->mimeTypeSupportMap[$mimeType])) {
			return $this->mimeTypeSupportMap[$mimeType];
		}

		$this->registerCoreProviders();
		$this->registerBootstrapProviders();
		$providerMimeTypes = array_keys($this->providers);
		foreach ($providerMimeTypes as $supportedMimeType) {
			if (preg_match($supportedMimeType, $mimeType)) {
				$this->mimeTypeSupportMap[$mimeType] = true;
				return true;
			}
		}
		$this->mimeTypeSupportMap[$mimeType] = false;
		return false;
	}

	/**
	 * Check if a preview can be generated for a file
	 *
	 * @param \OCP\Files\FileInfo $file
	 * @return bool
	 */
	public function isAvailable(\OCP\Files\FileInfo $file) {
		if (!$this->config->getSystemValue('enable_previews', true)) {
			return false;
		}

		$this->registerCoreProviders();
		if (!$this->isMimeSupported($file->getMimetype())) {
			return false;
		}

		$mount = $file->getMountPoint();
		if ($mount and !$mount->getOption('previews', true)) {
			return false;
		}

		foreach ($this->providers as $supportedMimeType => $providers) {
			if (preg_match($supportedMimeType, $file->getMimetype())) {
				foreach ($providers as $providerClosure) {
					$provider = $this->helper->getProvider($providerClosure);
					if (!($provider instanceof IProviderV2)) {
						continue;
					}

					if ($provider->isAvailable($file)) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/**
	 * List of enabled default providers
	 *
	 * The following providers are enabled by default:
	 *  - OC\Preview\PNG
	 *  - OC\Preview\JPEG
	 *  - OC\Preview\GIF
	 *  - OC\Preview\BMP
	 *  - OC\Preview\HEIC
	 *  - OC\Preview\XBitmap
	 *  - OC\Preview\MarkDown
	 *  - OC\Preview\MP3
	 *  - OC\Preview\TXT
	 *
	 * The following providers are disabled by default due to performance or privacy concerns:
	 *  - OC\Preview\Font
	 *  - OC\Preview\Illustrator
	 *  - OC\Preview\Movie
	 *  - OC\Preview\MSOfficeDoc
	 *  - OC\Preview\MSOffice2003
	 *  - OC\Preview\MSOffice2007
	 *  - OC\Preview\OpenDocument
	 *  - OC\Preview\PDF
	 *  - OC\Preview\Photoshop
	 *  - OC\Preview\Postscript
	 *  - OC\Preview\StarOffice
	 *  - OC\Preview\SVG
	 *  - OC\Preview\TIFF
	 *
	 * @return array
	 */
	protected function getEnabledDefaultProvider() {
		if ($this->defaultProviders !== null) {
			return $this->defaultProviders;
		}

		$imageProviders = [
			Preview\PNG::class,
			Preview\JPEG::class,
			Preview\GIF::class,
			Preview\BMP::class,
			Preview\HEIC::class,
			Preview\XBitmap::class,
			Preview\Krita::class,
		];

		$this->defaultProviders = $this->config->getSystemValue('enabledPreviewProviders', array_merge([
			Preview\MarkDown::class,
			Preview\MP3::class,
			Preview\TXT::class,
			Preview\OpenDocument::class,
		], $imageProviders));

		if (in_array(Preview\Image::class, $this->defaultProviders)) {
			$this->defaultProviders = array_merge($this->defaultProviders, $imageProviders);
		}
		$this->defaultProviders = array_unique($this->defaultProviders);
		return $this->defaultProviders;
	}

	/**
	 * Register the default providers (if enabled)
	 *
	 * @param string $class
	 * @param string $mimeType
	 */
	protected function registerCoreProvider($class, $mimeType, $options = []) {
		if (in_array(trim($class, '\\'), $this->getEnabledDefaultProvider())) {
			$this->registerProvider($mimeType, function () use ($class, $options) {
				return new $class($options);
			});
		}
	}

	/**
	 * Register the default providers (if enabled)
	 */
	protected function registerCoreProviders() {
		if ($this->registeredCoreProviders) {
			return;
		}
		$this->registeredCoreProviders = true;

		$this->registerCoreProvider(Preview\TXT::class, '/text\/plain/');
		$this->registerCoreProvider(Preview\MarkDown::class, '/text\/(x-)?markdown/');
		$this->registerCoreProvider(Preview\PNG::class, '/image\/png/');
		$this->registerCoreProvider(Preview\JPEG::class, '/image\/jpeg/');
		$this->registerCoreProvider(Preview\GIF::class, '/image\/gif/');
		$this->registerCoreProvider(Preview\BMP::class, '/image\/bmp/');
		$this->registerCoreProvider(Preview\XBitmap::class, '/image\/x-xbitmap/');
		$this->registerCoreProvider(Preview\Krita::class, '/application\/x-krita/');
		$this->registerCoreProvider(Preview\MP3::class, '/audio\/mpeg/');
		$this->registerCoreProvider(Preview\OpenDocument::class, '/application\/vnd.oasis.opendocument.*/');

		// SVG, Office and Bitmap require imagick
		if (extension_loaded('imagick')) {
			$checkImagick = new \Imagick();

			$imagickProviders = [
				'SVG'	=> ['mimetype' => '/image\/svg\+xml/', 'class' => Preview\SVG::class],
				'TIFF'	=> ['mimetype' => '/image\/tiff/', 'class' => Preview\TIFF::class],
				'PDF'	=> ['mimetype' => '/application\/pdf/', 'class' => Preview\PDF::class],
				'AI'	=> ['mimetype' => '/application\/illustrator/', 'class' => Preview\Illustrator::class],
				'PSD'	=> ['mimetype' => '/application\/x-photoshop/', 'class' => Preview\Photoshop::class],
				'EPS'	=> ['mimetype' => '/application\/postscript/', 'class' => Preview\Postscript::class],
				'TTF'	=> ['mimetype' => '/application\/(?:font-sfnt|x-font$)/', 'class' => Preview\Font::class],
				'HEIC'  => ['mimetype' => '/image\/hei(f|c)/', 'class' => Preview\HEIC::class],
			];

			foreach ($imagickProviders as $queryFormat => $provider) {
				$class = $provider['class'];
				if (!in_array(trim($class, '\\'), $this->getEnabledDefaultProvider())) {
					continue;
				}

				if (count($checkImagick->queryFormats($queryFormat)) === 1) {
					$this->registerCoreProvider($class, $provider['mimetype']);
				}
			}

			if (count($checkImagick->queryFormats('PDF')) === 1) {
				if (\OC_Helper::is_function_enabled('shell_exec')) {
					$officeFound = is_string($this->config->getSystemValue('preview_libreoffice_path', null));

					if (!$officeFound) {
						//let's see if there is libreoffice or openoffice on this machine
						$whichLibreOffice = shell_exec('command -v libreoffice');
						$officeFound = !empty($whichLibreOffice);
						if (!$officeFound) {
							$whichOpenOffice = shell_exec('command -v openoffice');
							$officeFound = !empty($whichOpenOffice);
						}
					}

					if ($officeFound) {
						$this->registerCoreProvider(Preview\MSOfficeDoc::class, '/application\/msword/');
						$this->registerCoreProvider(Preview\MSOffice2003::class, '/application\/vnd.ms-.*/');
						$this->registerCoreProvider(Preview\MSOffice2007::class, '/application\/vnd.openxmlformats-officedocument.*/');
						$this->registerCoreProvider(Preview\OpenDocument::class, '/application\/vnd.oasis.opendocument.*/');
						$this->registerCoreProvider(Preview\StarOffice::class, '/application\/vnd.sun.xml.*/');
					}
				}
			}
		}

		// Video requires avconv or ffmpeg
		if (in_array(Preview\Movie::class, $this->getEnabledDefaultProvider())) {
			$avconvBinary = \OC_Helper::findBinaryPath('avconv');
			$ffmpegBinary = $avconvBinary ? null : \OC_Helper::findBinaryPath('ffmpeg');

			if ($avconvBinary || $ffmpegBinary) {
				// FIXME // a bit hacky but didn't want to use subclasses
				\OC\Preview\Movie::$avconvBinary = $avconvBinary;
				\OC\Preview\Movie::$ffmpegBinary = $ffmpegBinary;

				$this->registerCoreProvider(Preview\Movie::class, '/video\/.*/');
			}
		}
	}

	private function registerBootstrapProviders() {
		$context = $this->bootstrapCoordinator->getRegistrationContext();

		if ($context === null) {
			// Just ignore for now
			return;
		}

		$providers = $context->getPreviewProviders();
		foreach ($providers as $provider) {
			$this->registerProvider($provider['mimeTypeRegex'], function () use ($provider) {
				try {
					return $this->container->query($provider['class']);
				} catch (QueryException $e) {
					return null;
				}
			});
		}
	}
}
