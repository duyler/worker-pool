<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Util;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function count;
use function defined;
use function function_exists;
use function in_array;
use function intval;

use const PHP_OS;
use const PHP_OS_FAMILY;
use const PHP_SAPI;
use const PHP_VERSION;

final class SystemInfo
{
    private static ?int $cachedCpuCores = null;

    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function getCpuCores(int $fallback = 4): int
    {
        if (null !== self::$cachedCpuCores) {
            return self::$cachedCpuCores;
        }

        $cores = $this->detectCpuCores();

        if (1 > $cores) {
            $this->logger->warning('Failed to detect CPU cores, using fallback', [
                'fallback' => $fallback,
            ]);
            $cores = $fallback;
        } else {
            $this->logger->debug('CPU cores detected', [
                'cores' => $cores,
                'os' => PHP_OS,
            ]);
        }

        self::$cachedCpuCores = $cores;

        return $cores;
    }

    public function getOsInfo(): array
    {
        return [
            'os' => PHP_OS,
            'os_family' => PHP_OS_FAMILY,
            'php_version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'cpu_cores' => $this->getCpuCores(),
        ];
    }

    public static function resetCache(): void
    {
        self::$cachedCpuCores = null;
    }

    public function isContainerEnvironment(): bool
    {
        return file_exists('/.dockerenv') || file_exists('/run/.containerenv');
    }

    public function supportsFdPassing(): bool
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return false;
        }

        if (!function_exists('socket_sendmsg') || !function_exists('socket_recvmsg')) {
            return false;
        }

        return defined('SCM_RIGHTS');
    }

    public function supportsReusePort(): bool
    {
        return defined('SO_REUSEPORT');
    }

    private function detectCpuCores(): int
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return $this->detectCpuCoresWindows();
        }

        if (PHP_OS === 'Linux') {
            return $this->detectCpuCoresLinux();
        }

        if (in_array(PHP_OS, ['Darwin', 'FreeBSD', 'OpenBSD', 'NetBSD'], true)) {
            return $this->detectCpuCoresBsd();
        }

        $cores = $this->detectCpuCoresLinux();
        if ($cores > 0) {
            return $cores;
        }

        $cores = $this->detectCpuCoresBsd();
        if ($cores > 0) {
            return $cores;
        }

        return 0;
    }

    private function detectCpuCoresWindows(): int
    {
        $process = popen('wmic cpu get NumberOfCores', 'rb');
        if (false !== $process) {
            fgets($process);
            $cores = intval(fgets($process));
            pclose($process);

            if ($cores > 0) {
                return $cores;
            }
        }

        $cores = getenv('NUMBER_OF_PROCESSORS');
        if (false !== $cores) {
            $cores = intval($cores);
            if ($cores > 0) {
                return $cores;
            }
        }

        return 0;
    }

    private function detectCpuCoresLinux(): int
    {
        $cores = $this->execCommand('nproc');
        if ($cores > 0) {
            return $cores;
        }

        if (is_readable('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            if (false !== $cpuinfo) {
                preg_match_all('/^processor/m', $cpuinfo, $matches);
                $cores = count($matches[0]);
                if ($cores > 0) {
                    return $cores;
                }
            }
        }

        $lscpu = $this->execCommandString('lscpu -p | grep -E -v "^#" | wc -l');
        if ($lscpu > 0) {
            return $lscpu;
        }

        return 0;
    }

    private function detectCpuCoresBsd(): int
    {
        $cores = $this->execCommandString('sysctl -n hw.ncpu');
        if ($cores > 0) {
            return $cores;
        }

        $cores = $this->execCommandString('sysctl -n hw.logicalcpu');
        if ($cores > 0) {
            return $cores;
        }

        $cores = $this->execCommandString('sysctl -n hw.physicalcpu');
        if ($cores > 0) {
            return $cores;
        }

        return 0;
    }

    private function execCommand(string $command): int
    {
        $process = popen($command, 'rb');
        if (false === $process) {
            return 0;
        }

        $output = stream_get_contents($process);
        pclose($process);

        if (false === $output) {
            return 0;
        }

        $cores = intval(trim($output));
        return $cores > 0 ? $cores : 0;
    }

    private function execCommandString(string $command): int
    {
        $output = $this->execCommandOutput($command);
        if (null === $output || '' === $output) {
            return 0;
        }

        $cores = intval(trim($output));
        return $cores > 0 ? $cores : 0;
    }

    private function execCommandOutput(string $command): ?string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (false === $process) {
            return null;
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return false !== $output ? $output : null;
    }
}
