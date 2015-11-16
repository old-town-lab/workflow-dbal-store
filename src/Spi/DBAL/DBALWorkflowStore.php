<?php
/**
 * @link    https://github.com/old-town/old-town-workflow
 * @author  Malofeykin Andrey  <and-rey2@yandex.ru>
 */
namespace OldTown\Workflow\Spi\DBAL;

use PDO;
use DateTime;
use SplObjectStorage;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Query\QueryBuilder;

use OldTown\PropertySet\PropertySetManager;
use OldTown\PropertySet\PropertySetInterface;

use OldTown\Workflow\Query\FieldExpression;
use OldTown\Workflow\Query\NestedExpression;
use OldTown\Workflow\Query\WorkflowExpressionQuery;

use OldTown\Workflow\Exception\StoreException;
use OldTown\Workflow\Exception\InvalidActionException;
use OldTown\Workflow\Exception\ArgumentNotNumericException;
use OldTown\Workflow\Exception\NotFoundWorkflowEntryException;

use OldTown\Workflow\Spi\DBAL\Exception\InvalidArgumentException;
use OldTown\Workflow\Spi\SimpleStep;
use OldTown\Workflow\Spi\StepInterface;
use OldTown\Workflow\Spi\SimpleWorkflowEntry;
use OldTown\Workflow\Spi\WorkflowEntryInterface;
use OldTown\Workflow\Spi\WorkflowStoreInterface;

/**
 * Class MemoryWorkflowStore
 *
 * @TODO доделать query, нормализовать mysql.sql дамп
 *
 * @package OldTown\Workflow\Spi\Memory
 */
class DBALWorkflowStore implements WorkflowStoreInterface
{
    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    /**
     * @var PropertySetInterface[]
     */
    private static $propertySetCache = [];

    /**
     * Вызывается один раз, при инициализации хранилища
     *
     * @param array $props
     * @throws Exception\InvalidArgumentException
     */
    public function init(array $props = [])
    {
        $this->connection = DriverManager::getConnection($props);
    }

    /**
     * Устанавливает состояние для текущего workflow
     *
     * @param integer $entryId id workflow
     * @param integer $state id состояния в которое переводится сущность workflow
     * @return void
     * @throws StoreException
     */
    public function setEntrystate($entryId, $state)
    {
        $queryBuilder = $this->getConnection()->createQueryBuilder();
        $queryBuilder->update('os_wf_entry')
            ->set('state', ':state')
            ->where('id', ':id')
            ->setParameters([
                'state' => $state,
                'id' => $entryId
            ]);
    }

    /**
     * Возвращает PropertySet that связанный с данным экземпляром workflow
     * @param integer $entryId id workflow
     * @return PropertySetInterface
     * @throws StoreException
     */
    public function getPropertySet($entryId)
    {
        if (array_key_exists($entryId, static::$propertySetCache)) {
            return static::$propertySetCache[$entryId];
        }

        if (!is_numeric($entryId)) {
            $errMsg = sprintf('Аргумент должен быть числом. Актуальное значение %s', $entryId);
            throw new ArgumentNotNumericException($errMsg);
        }
        $entryId = (integer)$entryId;

        $ps = PropertySetManager::getInstance('memory');
        static::$propertySetCache[$entryId] = $ps;

        return static::$propertySetCache[$entryId];
    }

    /**
     * Добавляет записи о шаге, и предыдущих шагах, в указанные таблицы
     *
     * @param string $stepTable
     * @param string $prevStepTable
     * @param int $entryId
     * @param int $stepId
     * @param string $owner
     * @param DateTime $startDate
     * @param DateTime $dueDate
     * @param string $status
     * @param array $previousIds
     * @param int $id
     * @return SimpleStep
     * @throws \Doctrine\DBAL\ConnectionException
     */
    protected function createStep(
        $stepTable,
        $prevStepTable,
        $entryId,
        $stepId,
        $owner,
        DateTime $startDate,
        DateTime $dueDate = null,
        $status,
        array $previousIds = [],
        $id = null
    ) {
        $this->getConnection()->beginTransaction();
        try {
            $values = [
                'entry_id' => ':entryId',
                'step_id' => ':stepId',
                'owner' => ':owner',
                'start_date' => ':startDate',
                'due_date' => ':dueDate',
                'status' => ':status'
            ];
            $parameters = [
                'entryId' => $entryId,
                'stepId' => $stepId,
                'owner' => $owner,
                'startDate' => ($startDate instanceof DateTime) ? $startDate->format(DateTime::ATOM) : null,
                'dueDate' => ($dueDate instanceof DateTime) ? $dueDate->format(DateTime::ATOM) : null,
                'status' => $status
            ];

            if ($id) {
                $values['id'] = ':id';
                $parameters['id'] = $id;
            }

            // Сохраняем текущий шаг
            $queryBuilder = $this->getConnection()->createQueryBuilder();
            $queryBuilder->insert($stepTable)
                ->values($values)
                ->setParameters($parameters)
                ->execute();
            if (!$id) {
                $id = $this->getConnection()->lastInsertId();
            }

            // Сохраняем предыдущие шаги
            $queryBuilder = $this->getConnection()->createQueryBuilder();
            $queryBuilder->insert($prevStepTable)
                ->values([
                    'id' => ':id',
                    'previous_id' => ':previousId',
                ])->setParameter('id', $id);
            foreach ($previousIds as $previousId) {
                $queryBuilder->setParameter('previousId', $previousId)
                    ->execute();
            }

            $this->getConnection()->commit();
        } catch (\Exception $e) {
            $this->getConnection()->rollBack();
            throw new StoreException('Unable create step', 0, $e);
        }

        return new SimpleStep(
            $id,
            $entryId,
            $stepId,
            0,
            $owner,
            $startDate,
            $dueDate,
            null,
            $status,
            $previousIds,
            null
        );
    }

    /**
     * Persists a step with the given parameters.
     *
     * @param integer $entryId id workflow
     * @param integer $stepId id шага
     * @param string $owner владелец шага
     * @param DateTime $startDate дата когда произошел старт шага
     * @param DateTime $dueDate
     * @param string $status статус
     * @param integer[] $previousIds Id предыдущих шагов
     * @throws StoreException
     * @return StepInterface объект описывающий сохраненный шаг workflow
     */
    public function createCurrentStep(
        $entryId,
        $stepId,
        $owner,
        DateTime $startDate,
        DateTime $dueDate = null,
        $status,
        array $previousIds = []
    ) {
        return $this->createStep(
            'os_current_step',
            'os_current_step_prev',
            $entryId,
            $stepId,
            $owner,
            $startDate,
            $dueDate,
            $status,
            $previousIds
        );
    }

    /**
     * Создает новую сущность workflow (не инициазированную)
     *
     * @param string $workflowName имя workflow, используемого для данной сущности
     * @throws StoreException
     * @return WorkflowEntryInterface
     */
    public function createEntry($workflowName)
    {
        $this->getConnection()->beginTransaction();
        try {
            $queryBuilder = $this->getConnection()->createQueryBuilder();
            $queryBuilder->insert('os_wf_entry')
                ->values([
                    'name' => ':name',
                    'state' => ':state',
                ])->setParameters([
                    'name' => $workflowName,
                    'state' => WorkflowEntryInterface::CREATED
                ])->execute();

            $id = $this->getConnection()->lastInsertId();
            $this->getConnection()->commit();
        } catch (\Exception $e) {
            $this->getConnection()->rollBack();
            throw new StoreException('Unable create entry', 0, $e);
        }

        return new SimpleWorkflowEntry(
            $id,
            $workflowName,
            WorkflowEntryInterface::CREATED
        );
    }

    /**
     * Общий код для findCurrent и findHistory
     *
     * @param integer $entryId id экземпляра workflow
     * @param string $stepTable Имя таблицы с шагами
     * @param string $prevTable Имя таблицы с предыдущими шагами
     *
     * @return \OldTown\Workflow\Spi\StepInterface[]
     */
    protected function findSteps($entryId, $stepTable, $prevTable)
    {
        try {
            // Вытягиваем все текущие шаги
            $queryBuilder = $this->getConnection()->createQueryBuilder();
            $queryBuilder->select([
                'id',
                'step_id',
                'action_id',
                'owner',
                'start_date',
                'finish_date',
                'due_date',
                'status',
                'caller'
            ])->from($stepTable)
                ->where('entry_id', ':entryId')
                ->setParameter('entryId', $entryId);

            $currentStepsArray = $queryBuilder->execute()->fetchAll();

            // Вытягиваем предыдущие шаги
            $currentStepsIds = [];
            foreach ($currentStepsArray as $currentStep) {
                $currentStepsIds[] = $currentStep['id'];
            }
            $queryBuilder = $this->getConnection()->createQueryBuilder();
            $queryBuilder->select('id', 'previous_id')
                ->from($prevTable)
                ->where($queryBuilder->expr()->in('id', $currentStepsIds));
            $previousIdsArray = $queryBuilder->execute()->fetchAll();

            // Объединяем выборки, и собираем объекты workflow
            $currentSteps = [];
            foreach ($currentStepsArray as $currentStep) {
                $previousIds = [];
                foreach ($previousIdsArray as $previousIdArray) {
                    if ($previousIdArray['id'] === $currentStep['id']) {
                        $previousIds[] = $previousIdArray['previous_id'];
                    }
                }

                $currentSteps[] = new SimpleStep(
                    $currentStep['id'],
                    $entryId,
                    $currentStep['step_id'],
                    $currentStep['action_id'],
                    $currentStep['owner'],
                    $currentStep['start_date'] ? new DateTime($currentStep['start_date']) : null,
                    $currentStep['due_date'] ? new DateTime($currentStep['due_date']) : null,
                    $currentStep['finish_date'] ? new DateTime($currentStep['finish_date']) : null,
                    $currentStep['status'],
                    $previousIds,
                    $currentStep['caller']
                );
            }
        } catch (\Exception $e) {
            throw new StoreException('Error finding steps', 0, $e);
        }

        return $currentSteps;
    }

    /**
     * Получения истории шагов
     *
     * @param entryId
     * @throws StoreException
     * @return StepInterface[]|SplObjectStorage
     */
    public function findHistorySteps($entryId)
    {
        return $this->findSteps(
            $entryId,
            'os_history_step',
            'os_history_step_prev'
        );
    }

    /**
     * Возвращает список шагов
     *
     * @param integer $entryId id экземпляра workflow
     * @throws \OldTown\Workflow\Exception\StoreException
     * @return StepInterface[]
     */
    public function findCurrentSteps($entryId)
    {
        return $this->findSteps(
            $entryId,
            'os_current_step',
            'os_current_step_prev'
        );
    }

    /**
     * Загрузить экземпляр workflow
     *
     * @param integer $entryId
     * @throws \OldTown\Workflow\Exception\StoreException
     * @return WorkflowEntryInterface
     */
    public function findEntry($entryId)
    {
        try {
            $queryBuilder = $this->getConnection()->createQueryBuilder();
            $queryBuilder->select('name', 'state')
                ->from('os_wf_entry')
                ->where('id', ':id')
                ->setParameter('id', $entryId);

            $entryData = $queryBuilder->execute()->fetch();
            if (!$entryData) {
                throw new NotFoundWorkflowEntryException('Entry with id ' . $entryId . ' not found');
            }
        } catch (\Exception $e) {
            throw new StoreException('Error finding entry', 0, $e);
        }

        return new SimpleWorkflowEntry($entryId, $entryData['name'], $entryData['state']);
    }

    /**
     * Помечате выбранный шаг, как выполенный
     *
     * @param StepInterface $step шаг который хоим пометить как выполненный
     * @param integer $actionId Действие которое привело к окончанию шага
     * @param DateTime $finishDate дата когда шаг был финиширован
     * @param string $status
     * @param string $caller Информация о том, кто вызвал шаг что бы его закончить
     * @throws StoreException
     * @return SimpleStep finished step
     */
    public function markFinished(StepInterface $step, $actionId, DateTime $finishDate, $status, $caller)
    {
        $this->getConnection()->beginTransaction();
        try {
            $queryBuilder = $this->getConnection()->createQueryBuilder();
            $queryBuilder->update('os_current_step')
                ->set('status', ':status')
                ->set('action_id', ':actionId')
                ->set('finish_date', ':finishDate')
                ->set('caller', ':caller')
                ->where('step_id', ':stepId')
                ->setParameters([
                    'status' => $status,
                    'actionId' => $actionId,
                    'finishDate' => $finishDate ? $finishDate->format(DateTime::ATOM) : null,
                    'caller' => $caller,
                    'stepId' => $step->getId()
                ]);
            $queryBuilder->execute();
        } catch (\Exception $e) {
            throw new StoreException('Unable mark finished', 0, $e);
        }

        $step->setStatus($status);
        $step->setActionId($actionId);
        $step->setFinishDate($finishDate);
        $step->setCaller($caller);

        return $step;
    }

    /**
     * Called when a step is finished and can be moved to workflow history.
     *
     * @param StepInterface $step шаг, который переносится в историю
     * @return $this
     */
    public function moveToHistory(StepInterface $step)
    {
        $this->getConnection()->beginTransaction();
        try {
            // Созаем историческую запись
            $this->createStep(
                'os_history_step',
                'os_history_step_prev',
                $step->getEntryId(),
                $step->getStepId(),
                $step->getOwner(),
                $step->getStartDate(),
                $step->getDueDate(),
                $step->getStatus(),
                $step->getPreviousStepIds()
            );

            // Удаляем из текущих шагов
            $this->getConnection()->createQueryBuilder()
                ->delete('os_current_step')
                ->where('id', ':id')
                ->setParameter('id', $step->getId())
                ->execute();

            // Удаляем связи
            $this->getConnection()->createQueryBuilder()
                ->delete('os_current_step_prev')
                ->where('id', ':id')
                ->setParameter('id', $step->getId())
                ->execute();

            $this->getConnection()->commit();
        } catch (\Exception $e) {
            $this->getConnection()->rollBack();
            throw new StoreException('Unable move to history', 0, $e);
        }
    }

    /**
     * @param WorkflowExpressionQuery $query
     * @throws StoreException
     * @return array
     */
    public function query(WorkflowExpressionQuery $query)
    {
        /** @var FieldExpression $expression */
        $expression = $query->getExpression();
        if ($expression->isNegate()) {
            throw new StoreException('Поддержка negate не реализована, и вообще сомнительная это штука');
        }

        if ($query instanceof NestedExpression) {
            throw new StoreException('Сей функционал пока не реализован');
        } else {
            switch ($expression->getContext()) {
                case FieldExpression::ENTRY:
                    return $this->queryEntry($expression);
                case FieldExpression::CURRENT_STEPS:
                case FieldExpression::HISTORY_STEPS:
                    throw new StoreException('Сей функционал пока не реализован');
                default:
                    throw new InvalidArgumentException('Неизвестный контекст запроса');
            }
        }
    }

    /**
     * Поиск по экземплярам workflow
     *
     * @param FieldExpression $expression
     *
     * @return array
     */
    protected function queryEntry(FieldExpression $expression)
    {
        $queryBuilder = $this->getConnection()
            ->createQueryBuilder()
            ->select('id')
            ->from('os_wf_entry');

        switch ($expression->getField()) {
            case FieldExpression::NAME:
                $this->addSimpleWhere(
                    'name',
                    $queryBuilder,
                    $expression
                );
                break;
            case FieldExpression::STATE:
                $this->addSimpleWhere(
                    'state',
                    $queryBuilder,
                    $expression
                );
                break;
            default:
                throw new InvalidActionException('Неизвестное поле запроса');
        }

        $results = [];
        $entryIds = $queryBuilder->execute()->fetchAll(PDO::FETCH_COLUMN);
        foreach ($entryIds as $entryId) {
            $results[$entryId] = $entryId;
        }

        return $results;
    }

    /**
     * Строим условие
     *
     * @param $fieldName
     * @param QueryBuilder $queryBuilder
     * @param FieldExpression $expression
     */
    protected function addSimpleWhere($fieldName, $queryBuilder, $expression)
    {
        if ($expression->getValue() === null) {
            if ($expression->getOperator() === FieldExpression::EQUALS) {
                $queryBuilder->where($queryBuilder->expr()->isNull($fieldName));
                return ;
            }

            if ($expression->getOperator() === FieldExpression::NOT_EQUALS) {
                $queryBuilder->where($queryBuilder->expr()->isNotNull($fieldName));
                return ;
            }
        }

        switch ($expression->getOperator()) {
            case FieldExpression::EQUALS:
                $queryBuilder->where($queryBuilder->expr()->eq($fieldName, ':value'));
                break;
            case FieldExpression::NOT_EQUALS:
                $queryBuilder->where($queryBuilder->expr()->neq($fieldName, ':value'));
                break;
            case FieldExpression::GT:
                $queryBuilder->where($queryBuilder->expr()->gt($fieldName, ':value'));
                break;
            case FieldExpression::LT:
                $queryBuilder->where($queryBuilder->expr()->lt($fieldName, ':value'));
                break;
            default:
                throw new InvalidActionException('Некорректный оператор');
        }

        $queryBuilder->setParameter('value', $expression->getValue());
    }

    /**
     * @return \Doctrine\DBAL\Connection
     */
    public function getConnection()
    {
        return $this->connection;
    }
}
