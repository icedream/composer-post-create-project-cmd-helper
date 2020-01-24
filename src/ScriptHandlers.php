<?php declare(strict_types=1);
/**
 * @author      Carl Kittelberger <icedream@icedream.pw>
 */

namespace Icedream\ComposerPostCreateProjectCmdHelper;

use Composer\Script\Event;
use Composer\Installer\PackageEvent;
use Composer\DependencyResolver\Operation\UninstallOperation;

class ScriptHandlers
{
	public static function cleanUp(Event $event): int
	{
		$io = $event->getIO();
		$composer = $event->getComposer();
		
		$setup = new Setup(
			$event->getComposer(),
			$event->getIO());

		return $setup->cleanUp();
	}
}