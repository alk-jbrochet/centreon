<?php

namespace CentreonRemote\Domain\Service;

use Centreon\Domain\Entity\Command;
use Centreon\Domain\Entity\Task;
use Centreon\Domain\Repository\TaskRepository;
use Centreon\Domain\Service\KeyGeneratorInterface;
use Centreon\Infrastructure\Service\CentcoreCommandService;
use Centreon\Infrastructure\Service\CentreonDBManagerService;
use Centreon\Infrastructure\Service\Exception\NotFoundException;

class TaskService
{
    /**
     * @var KeyGeneratorInterface
     */
    private $gen;

    /**
     * @var CentreonDBManagerService
     */
    private $dbManager;

    /**
     * @var CentcoreCommandService
     */
    private $cmdService;

    /**
     * @var \CentreonRestHttp
     */
    private $centreonRestHttp;

    /**
     * @return CentcoreCommandService
     */
    public function getCmdService(): CentcoreCommandService
    {
        return $this->cmdService;
    }

    /**
     * @param CentcoreCommandService $cmdService
     */
    public function setCmdService(CentcoreCommandService $cmdService): void
    {
        $this->cmdService = $cmdService;
    }

    /**
     * @return KeyGeneratorInterface
     */
    public function getGen(): KeyGeneratorInterface
    {
        return $this->gen;
    }

    /**
     * @return CentreonDBManagerService
     */
    public function getDbManager(): CentreonDBManagerService
    {
        return $this->dbManager;
    }

    /**
     * @return \CentreonRestHttp
     */
    public function setCentreonRestHttp(\CentreonRestHttp $centreonRestHttp): void
    {
        $this->centreonRestHttp = $centreonRestHttp;
    }

    /**
     * TaskService constructor
     * @param KeyGeneratorInterface $generator
     * @param CentreonDBManagerService $dbManager
     */
    public function __construct(
        KeyGeneratorInterface $generator,
        CentreonDBManagerService $dbManager,
        CentcoreCommandService $cmdService
    ) {
        $this->gen = $generator;
        $this->dbManager = $dbManager;
        $this->cmdService = $cmdService;
    }

    /**
     * Adds a new task
     *
     * @param string $type
     * @param array $params
     * @param int $parentId
     * @return int|bool
     */
    public function addTask(string $type, array $params, int $parentId = null)
    {
        $newTask = new Task();
        $newTask->setStatus(Task::STATE_PENDING);
        $newTask->setParams(serialize($params));
        $newTask->setParentId($parentId);

        switch ($type) {
            case Task::TYPE_EXPORT:
            case Task::TYPE_IMPORT:
                $newTask->setType($type);
                $result = $this->getDbManager()->getAdapter('configuration_db')->insert('task', $newTask->toArray());

                $cmd = new Command();
                $cmd->setCommandLine(Command::COMMAND_START_IMPEX_WORKER);
                $cmdWritten = $this->getCmdService()->sendCommand($cmd);
                break;
            default:
                return false;
        }

        return ($result && $cmdWritten) ? $result : false;
    }

    /**
     * Get Existing Task status
     * @param string $taskId
     * @return null
     */
    public function getStatus(string $taskId)
    {
        $task = $this->getDbManager()->getAdapter('configuration_db')->getRepository(TaskRepository::class)
            ->findOneById($taskId);
        return $task ? $task->getStatus() : null;
    }

    /**
     * Get remote existing task status by parent id
     *
     * @param int $parentId the parent task id on remote server
     * @param string $serverIp the ip address of the remote server
     * @param string $centreonFolder the folder of centreon on remote server
     * @return null
     */
    public function getRemoteStatusByParent(int $parentId, string $serverIp, string $centreonFolder)
    {
        $query = "SELECT params FROM task WHERE id = '" . $parentId  . "'";

        try {
            $remoteDataResult = $this->getDbManager()->getAdapter('configuration_db')->query($query)->results();
            $result = unserialize($remoteDataResult[0]->params);

            $httpMethod = $result['params']['http_method'];
            $httpPort = $result['params']['http_port'];
            $noCheckCertificate = $result['params']['no_check_certificate'];
            $noProxy = $result['params']['no_proxy'];

            $url = "";
            if (parse_url($serverIp, PHP_URL_SCHEME)) {
                $url = $serverIp;
            } else {
                $url = ($httpMethod ?? 'http') . '://' . $serverIp . ($httpPort ? ':' . $httpPort : '');
            }
            $url .= '/' . $centreonFolder . '/api/external.php?object=centreon_task_service&action=getTaskStatusByParent';

            $result = $this->centreonRestHttp->call(
                $url,
                'POST',
                ['parent_id' => $parentId],
                null,
                false,
                $noCheckCertificate,
                $noProxy
            );

            return isset($result['status']) ? $result['status'] : null;
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * Get existing task status by parent id
     *
     * @param int $parentId the parent task id on remote server
     * @return null
     */
    public function getStatusByParent(int $parentId)
    {
        $task = $this->getDbManager()->getAdapter('configuration_db')->getRepository(TaskRepository::class)
            ->findOneByParentId($parentId);
        return $task ? $task->getStatus() : null;
    }

    /**
     * Update task status
     * @param string $taskId
     * @param string $status
     * @return mixed
     * @throws NotFoundException
     * @throws \Exception
     */
    public function updateStatus(string $taskId, string $status)
    {
        $task = $this->getDbManager()->getAdapter('configuration_db')->getRepository(TaskRepository::class)->findOneById($taskId);
        if (!in_array($status, $task->getStatuses())) {
            return false;
        }

        $result = $this->getDbManager()->getAdapter('configuration_db')->getRepository(TaskRepository::class)->updateStatus($status, $taskId);
        return $result;
    }
}
