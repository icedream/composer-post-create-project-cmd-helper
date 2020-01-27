<?php declare(strict_types=1);
/**
 * @author      Carl Kittelberger <icedream@icedream.pw>
 */

namespace Icedream\ComposerPostCreateProjectCmdHelper;

use Composer\Command\RemoveCommand;
use Composer\Composer;
use Composer\Factory;
use Composer\Config\JsonConfigSource;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Request;
use Composer\Installer;
use Composer\Installer\PackageEvent;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\BasePackage;
use Composer\Script\CommandEvent;
use Composer\Script\Event;
use Exception;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

// ref https://github.com/composer/composer/blob/c876613d5c651d1aa0f0d6621d7955bfe09520e2/src/Composer/Command/RemoveCommand.php#L67-L161

class Setup
{
	protected static $helperComposerJson;

	protected static function getHelperComposerJson(): array
	{
		if (self::$helperComposerJson !== null)
		{
			return self::$helperComposerJson;
		}
		self::$helperComposerJson = json_decode(file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'composer.json'), true);
		return self::$helperComposerJson;
	}

	/**
	 * @var Composer
	 */
	private $composer;
	
	/**
	 * @var IOInterface
	 */
	private $io;
	
	/**
	 * @var array
	 */
	private $createProjectConfig;

	public function __construct(Composer $composer, IOInterface $io)
	{
		$this->composer = $composer;
		$this->io = $io;
		$extra = $composer->getPackage()->getExtra();
		if (!empty($extra) && !empty($extra['create-project']))
		{
			$this->createProjectConfig = $extra['create-project'];
		} else {
			$this->createProjectConfig = [];
		}
	}

	private function ensureString(string $configName, bool $allowNull = false): string
	{
		if (!array_key_exists($configName, $this->createProjectConfig))
		{
			return $allowNull ? null : "";
		}
		$value = $this->createProjectConfig[$configName];
		if ($value === null && !$allowNull)
		{
			$this->io->writeError("<warning>Configuration value extra.create-project.$configName expected to not be null but is, ignoring value.</warning>");
			return "";
		}
		if (!is_string($value))
		{
			$this->io->writeError("<warning>Configuration value extra.create-project.$configName expected to be a string but is not, ignoring value.</warning>");
			return $allowNull ? null : "";
		}
		return $value;
	}

	private function ensureArray(string $configName, bool $allowNull = false): array
	{
		if (!array_key_exists($configName, $this->createProjectConfig))
		{
			return $allowNull ? null : [];
		}
		$value = $this->createProjectConfig[$configName];
		if ($value === null && !$allowNull)
		{
			$this->io->writeError("<warning>Configuration value extra.create-project.$configName expected to not be null but is, ignoring value.</warning>");
			return [];
		}
		if (!is_array($value))
		{
			$this->io->writeError("<warning>Configuration value extra.create-project.$configName expected to be an array but is not, assuming empty array.</warning>");
			return [];
		}
		return $value;
	}

	public function cleanUp() : int
	{
		// Remove post-create-project-cmd scripts completely
		$this->removeProperty('scripts.post-create-project-cmd');

		// Remove extra config for our helper
		$this->removeProperty('extra.create-project');

		// Remove dependencies only used on project creation
		$this->io->write("<info>Removing no longer needed dependencies…</info>");
		$this->removePackagesFromComposerJson(
			$this->ensureArray('remove-require'),
			$this->ensureArray('remove-require-dev'));
		$this->removePackagesFromComposerJson([
			self::getHelperComposerJson()['name'], // this is composer-post-create-project-cmd-helper
		]);

		return $this->update(array_merge(
			$this->ensureArray('remove-require'),
			$this->ensureArray('remove-require-dev'),
			[self::getHelperComposerJson()['name']],
		));
	}

	public function removeHelperFromComposerJson()
	{
		$this->removePackagesFromComposerJson([
			self::getHelperComposerJson()['name'],
		]);
	}

	public function removeProperty(string $name)
	{
		$file = Factory::getComposerFile();

		$composerJsonFile = new JsonFile($file);
		$composerJson = $composerJsonFile->read();

		$composerConfig = new JsonConfigSource($composerJsonFile);
		$composerConfig->removeProperty($name);
	}

	public function removePackagesFromComposerJson(array $requiredPackages = [], array $devRequiredPackages = [])
	{
		$file = Factory::getComposerFile();

		$composerJsonFile = new JsonFile($file);
		$composerJson = $composerJsonFile->read();

		$composerConfig = new JsonConfigSource($composerJsonFile);

		// make sure name checks are done case insensitively
		foreach (array('require', 'require-dev') as $linkType) {
			if (isset($composerJson[$linkType])) {
				foreach ($composerJson[$linkType] as $name => $version) {
					$composerJson[$linkType][strtolower($name)] = $name;
				}
			}
		}

		foreach ([
			'require' => $requiredPackages,
			'require-dev' => $devRequiredPackages,
		] as $type => $packages)
		{
			foreach ($packages as $package)
			{
				if (isset($composerJson[$type][$package]))
				{
					$composerConfig->removeLink($type, $package);
					continue;
				}
				
				if (isset($composerJson[$type]) && $matches = preg_grep(BasePackage::packageNameToRegexp($package), array_keys($composerJson[$type])))
				{
					foreach ($matches as $matchedPackage)
					{
						$composerConfig->removeLink($type, $matchedPackage);
					}
					continue;
				}

				$this->io->writeError('<warning>' . $package . ' requested to be removed but not required in composer.json, ignoring.</warning>');
			}
		}
	}

	public function update($packageWhitelist = null) : int
	{
		$install = Installer::create($this->io, $this->composer);
		$install->setUpdate(true);
		if (!empty($packageWhitelist))
		{
			$install->setUpdateWhitelist($allPackages);
		}
		$status = $install->run();
		if ($status !== 0) {
			$this->io->writeError("\n".'<error>Removal failed, ignoring.</error>');
			file_put_contents($composerJsonFile->getPath(), $composerBackup);
		}

		return $status;
	}
}