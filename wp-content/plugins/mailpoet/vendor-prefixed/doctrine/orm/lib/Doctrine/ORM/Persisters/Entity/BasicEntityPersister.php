<?php
declare (strict_types=1);
namespace MailPoetVendor\Doctrine\ORM\Persisters\Entity;
if (!defined('ABSPATH')) exit;
use BackedEnum;
use MailPoetVendor\Doctrine\Common\Collections\Criteria;
use MailPoetVendor\Doctrine\Common\Collections\Expr\Comparison;
use MailPoetVendor\Doctrine\Common\Util\ClassUtils;
use MailPoetVendor\Doctrine\DBAL\Connection;
use MailPoetVendor\Doctrine\DBAL\LockMode;
use MailPoetVendor\Doctrine\DBAL\Platforms\AbstractPlatform;
use MailPoetVendor\Doctrine\DBAL\Result;
use MailPoetVendor\Doctrine\DBAL\Types\Type;
use MailPoetVendor\Doctrine\DBAL\Types\Types;
use MailPoetVendor\Doctrine\Deprecations\Deprecation;
use MailPoetVendor\Doctrine\ORM\EntityManagerInterface;
use MailPoetVendor\Doctrine\ORM\Mapping\ClassMetadata;
use MailPoetVendor\Doctrine\ORM\Mapping\MappingException;
use MailPoetVendor\Doctrine\ORM\Mapping\QuoteStrategy;
use MailPoetVendor\Doctrine\ORM\OptimisticLockException;
use MailPoetVendor\Doctrine\ORM\PersistentCollection;
use MailPoetVendor\Doctrine\ORM\Persisters\Exception\CantUseInOperatorOnCompositeKeys;
use MailPoetVendor\Doctrine\ORM\Persisters\Exception\InvalidOrientation;
use MailPoetVendor\Doctrine\ORM\Persisters\Exception\UnrecognizedField;
use MailPoetVendor\Doctrine\ORM\Persisters\SqlExpressionVisitor;
use MailPoetVendor\Doctrine\ORM\Persisters\SqlValueVisitor;
use MailPoetVendor\Doctrine\ORM\Query;
use MailPoetVendor\Doctrine\ORM\Query\QueryException;
use MailPoetVendor\Doctrine\ORM\Repository\Exception\InvalidFindByCall;
use MailPoetVendor\Doctrine\ORM\UnitOfWork;
use MailPoetVendor\Doctrine\ORM\Utility\IdentifierFlattener;
use MailPoetVendor\Doctrine\ORM\Utility\PersisterHelper;
use LengthException;
use function array_combine;
use function array_keys;
use function array_map;
use function array_merge;
use function array_search;
use function array_unique;
use function array_values;
use function assert;
use function count;
use function implode;
use function is_array;
use function is_object;
use function reset;
use function spl_object_id;
use function sprintf;
use function str_contains;
use function strtoupper;
use function trim;
class BasicEntityPersister implements EntityPersister
{
 private static $comparisonMap = [Comparison::EQ => '= %s', Comparison::NEQ => '!= %s', Comparison::GT => '> %s', Comparison::GTE => '>= %s', Comparison::LT => '< %s', Comparison::LTE => '<= %s', Comparison::IN => 'IN (%s)', Comparison::NIN => 'NOT IN (%s)', Comparison::CONTAINS => 'LIKE %s', Comparison::STARTS_WITH => 'LIKE %s', Comparison::ENDS_WITH => 'LIKE %s'];
 protected $class;
 protected $conn;
 protected $platform;
 protected $em;
 protected $queuedInserts = [];
 protected $columnTypes = [];
 protected $quotedColumns = [];
 private $insertSql;
 protected $quoteStrategy;
 private $identifierFlattener;
 protected $currentPersisterContext;
 private $limitsHandlingContext;
 private $noLimitsContext;
 public function __construct(EntityManagerInterface $em, ClassMetadata $class)
 {
 $this->em = $em;
 $this->class = $class;
 $this->conn = $em->getConnection();
 $this->platform = $this->conn->getDatabasePlatform();
 $this->quoteStrategy = $em->getConfiguration()->getQuoteStrategy();
 $this->identifierFlattener = new IdentifierFlattener($em->getUnitOfWork(), $em->getMetadataFactory());
 $this->noLimitsContext = $this->currentPersisterContext = new CachedPersisterContext($class, new Query\ResultSetMapping(), \false);
 $this->limitsHandlingContext = new CachedPersisterContext($class, new Query\ResultSetMapping(), \true);
 }
 public function getClassMetadata()
 {
 return $this->class;
 }
 public function getResultSetMapping()
 {
 return $this->currentPersisterContext->rsm;
 }
 public function addInsert($entity)
 {
 $this->queuedInserts[spl_object_id($entity)] = $entity;
 }
 public function getInserts()
 {
 return $this->queuedInserts;
 }
 public function executeInserts()
 {
 if (!$this->queuedInserts) {
 return [];
 }
 $postInsertIds = [];
 $idGenerator = $this->class->idGenerator;
 $isPostInsertId = $idGenerator->isPostInsertGenerator();
 $stmt = $this->conn->prepare($this->getInsertSQL());
 $tableName = $this->class->getTableName();
 foreach ($this->queuedInserts as $entity) {
 $insertData = $this->prepareInsertData($entity);
 if (isset($insertData[$tableName])) {
 $paramIndex = 1;
 foreach ($insertData[$tableName] as $column => $value) {
 $stmt->bindValue($paramIndex++, $value, $this->columnTypes[$column]);
 }
 }
 $stmt->executeStatement();
 if ($isPostInsertId) {
 $generatedId = $idGenerator->generateId($this->em, $entity);
 $id = [$this->class->identifier[0] => $generatedId];
 $postInsertIds[] = ['generatedId' => $generatedId, 'entity' => $entity];
 } else {
 $id = $this->class->getIdentifierValues($entity);
 }
 if ($this->class->requiresFetchAfterChange) {
 $this->assignDefaultVersionAndUpsertableValues($entity, $id);
 }
 }
 $this->queuedInserts = [];
 return $postInsertIds;
 }
 protected function assignDefaultVersionAndUpsertableValues($entity, array $id)
 {
 $values = $this->fetchVersionAndNotUpsertableValues($this->class, $id);
 foreach ($values as $field => $value) {
 $value = Type::getType($this->class->fieldMappings[$field]['type'])->convertToPHPValue($value, $this->platform);
 $this->class->setFieldValue($entity, $field, $value);
 }
 }
 protected function fetchVersionAndNotUpsertableValues($versionedClass, array $id)
 {
 $columnNames = [];
 foreach ($this->class->fieldMappings as $key => $column) {
 if (isset($column['generated']) || $this->class->isVersioned && $key === $versionedClass->versionField) {
 $columnNames[$key] = $this->quoteStrategy->getColumnName($key, $versionedClass, $this->platform);
 }
 }
 $tableName = $this->quoteStrategy->getTableName($versionedClass, $this->platform);
 $identifier = $this->quoteStrategy->getIdentifierColumnNames($versionedClass, $this->platform);
 // FIXME: Order with composite keys might not be correct
 $sql = 'SELECT ' . implode(', ', $columnNames) . ' FROM ' . $tableName . ' WHERE ' . implode(' = ? AND ', $identifier) . ' = ?';
 $flatId = $this->identifierFlattener->flattenIdentifier($versionedClass, $id);
 $values = $this->conn->fetchNumeric($sql, array_values($flatId), $this->extractIdentifierTypes($id, $versionedClass));
 if ($values === \false) {
 throw new LengthException('Unexpected empty result for database query.');
 }
 $values = array_combine(array_keys($columnNames), $values);
 if (!$values) {
 throw new LengthException('Unexpected number of database columns.');
 }
 return $values;
 }
 private function extractIdentifierTypes(array $id, ClassMetadata $versionedClass) : array
 {
 $types = [];
 foreach ($id as $field => $value) {
 $types = array_merge($types, $this->getTypes($field, $value, $versionedClass));
 }
 return $types;
 }
 public function update($entity)
 {
 $tableName = $this->class->getTableName();
 $updateData = $this->prepareUpdateData($entity);
 if (!isset($updateData[$tableName])) {
 return;
 }
 $data = $updateData[$tableName];
 if (!$data) {
 return;
 }
 $isVersioned = $this->class->isVersioned;
 $quotedTableName = $this->quoteStrategy->getTableName($this->class, $this->platform);
 $this->updateTable($entity, $quotedTableName, $data, $isVersioned);
 if ($this->class->requiresFetchAfterChange) {
 $id = $this->class->getIdentifierValues($entity);
 $this->assignDefaultVersionAndUpsertableValues($entity, $id);
 }
 }
 protected final function updateTable($entity, $quotedTableName, array $updateData, $versioned = \false) : void
 {
 $set = [];
 $types = [];
 $params = [];
 foreach ($updateData as $columnName => $value) {
 $placeholder = '?';
 $column = $columnName;
 switch (\true) {
 case isset($this->class->fieldNames[$columnName]):
 $fieldName = $this->class->fieldNames[$columnName];
 $column = $this->quoteStrategy->getColumnName($fieldName, $this->class, $this->platform);
 if (isset($this->class->fieldMappings[$fieldName]['requireSQLConversion'])) {
 $type = Type::getType($this->columnTypes[$columnName]);
 $placeholder = $type->convertToDatabaseValueSQL('?', $this->platform);
 }
 break;
 case isset($this->quotedColumns[$columnName]):
 $column = $this->quotedColumns[$columnName];
 break;
 }
 $params[] = $value;
 $set[] = $column . ' = ' . $placeholder;
 $types[] = $this->columnTypes[$columnName];
 }
 $where = [];
 $identifier = $this->em->getUnitOfWork()->getEntityIdentifier($entity);
 foreach ($this->class->identifier as $idField) {
 if (!isset($this->class->associationMappings[$idField])) {
 $params[] = $identifier[$idField];
 $types[] = $this->class->fieldMappings[$idField]['type'];
 $where[] = $this->quoteStrategy->getColumnName($idField, $this->class, $this->platform);
 continue;
 }
 $params[] = $identifier[$idField];
 $where[] = $this->quoteStrategy->getJoinColumnName($this->class->associationMappings[$idField]['joinColumns'][0], $this->class, $this->platform);
 $targetMapping = $this->em->getClassMetadata($this->class->associationMappings[$idField]['targetEntity']);
 $targetType = PersisterHelper::getTypeOfField($targetMapping->identifier[0], $targetMapping, $this->em);
 if ($targetType === []) {
 throw UnrecognizedField::byFullyQualifiedName($this->class->name, $targetMapping->identifier[0]);
 }
 $types[] = reset($targetType);
 }
 if ($versioned) {
 $versionField = $this->class->versionField;
 assert($versionField !== null);
 $versionFieldType = $this->class->fieldMappings[$versionField]['type'];
 $versionColumn = $this->quoteStrategy->getColumnName($versionField, $this->class, $this->platform);
 $where[] = $versionColumn;
 $types[] = $this->class->fieldMappings[$versionField]['type'];
 $params[] = $this->class->reflFields[$versionField]->getValue($entity);
 switch ($versionFieldType) {
 case Types::SMALLINT:
 case Types::INTEGER:
 case Types::BIGINT:
 $set[] = $versionColumn . ' = ' . $versionColumn . ' + 1';
 break;
 case Types::DATETIME_MUTABLE:
 $set[] = $versionColumn . ' = CURRENT_TIMESTAMP';
 break;
 }
 }
 $sql = 'UPDATE ' . $quotedTableName . ' SET ' . implode(', ', $set) . ' WHERE ' . implode(' = ? AND ', $where) . ' = ?';
 $result = $this->conn->executeStatement($sql, $params, $types);
 if ($versioned && !$result) {
 throw OptimisticLockException::lockFailed($entity);
 }
 }
 protected function deleteJoinTableRecords(array $identifier, array $types) : void
 {
 foreach ($this->class->associationMappings as $mapping) {
 if ($mapping['type'] !== ClassMetadata::MANY_TO_MANY) {
 continue;
 }
 // @Todo this only covers scenarios with no inheritance or of the same level. Is there something
 // like self-referential relationship between different levels of an inheritance hierarchy? I hope not!
 $selfReferential = $mapping['targetEntity'] === $mapping['sourceEntity'];
 $class = $this->class;
 $association = $mapping;
 $otherColumns = [];
 $otherKeys = [];
 $keys = [];
 if (!$mapping['isOwningSide']) {
 $class = $this->em->getClassMetadata($mapping['targetEntity']);
 $association = $class->associationMappings[$mapping['mappedBy']];
 }
 $joinColumns = $mapping['isOwningSide'] ? $association['joinTable']['joinColumns'] : $association['joinTable']['inverseJoinColumns'];
 if ($selfReferential) {
 $otherColumns = !$mapping['isOwningSide'] ? $association['joinTable']['joinColumns'] : $association['joinTable']['inverseJoinColumns'];
 }
 foreach ($joinColumns as $joinColumn) {
 $keys[] = $this->quoteStrategy->getJoinColumnName($joinColumn, $class, $this->platform);
 }
 foreach ($otherColumns as $joinColumn) {
 $otherKeys[] = $this->quoteStrategy->getJoinColumnName($joinColumn, $class, $this->platform);
 }
 if (isset($mapping['isOnDeleteCascade'])) {
 continue;
 }
 $joinTableName = $this->quoteStrategy->getJoinTableName($association, $this->class, $this->platform);
 $this->conn->delete($joinTableName, array_combine($keys, $identifier), $types);
 if ($selfReferential) {
 $this->conn->delete($joinTableName, array_combine($otherKeys, $identifier), $types);
 }
 }
 }
 public function delete($entity)
 {
 $class = $this->class;
 $identifier = $this->em->getUnitOfWork()->getEntityIdentifier($entity);
 $tableName = $this->quoteStrategy->getTableName($class, $this->platform);
 $idColumns = $this->quoteStrategy->getIdentifierColumnNames($class, $this->platform);
 $id = array_combine($idColumns, $identifier);
 $types = $this->getClassIdentifiersTypes($class);
 $this->deleteJoinTableRecords($identifier, $types);
 return (bool) $this->conn->delete($tableName, $id, $types);
 }
 protected function prepareUpdateData($entity, bool $isInsert = \false)
 {
 $versionField = null;
 $result = [];
 $uow = $this->em->getUnitOfWork();
 $versioned = $this->class->isVersioned;
 if ($versioned !== \false) {
 $versionField = $this->class->versionField;
 }
 foreach ($uow->getEntityChangeSet($entity) as $field => $change) {
 if (isset($versionField) && $versionField === $field) {
 continue;
 }
 if (isset($this->class->embeddedClasses[$field])) {
 continue;
 }
 $newVal = $change[1];
 if (!isset($this->class->associationMappings[$field])) {
 $fieldMapping = $this->class->fieldMappings[$field];
 $columnName = $fieldMapping['columnName'];
 if (!$isInsert && isset($fieldMapping['notUpdatable'])) {
 continue;
 }
 if ($isInsert && isset($fieldMapping['notInsertable'])) {
 continue;
 }
 $this->columnTypes[$columnName] = $fieldMapping['type'];
 $result[$this->getOwningTable($field)][$columnName] = $newVal;
 continue;
 }
 $assoc = $this->class->associationMappings[$field];
 // Only owning side of x-1 associations can have a FK column.
 if (!$assoc['isOwningSide'] || !($assoc['type'] & ClassMetadata::TO_ONE)) {
 continue;
 }
 if ($newVal !== null) {
 $oid = spl_object_id($newVal);
 if (isset($this->queuedInserts[$oid]) || $uow->isScheduledForInsert($newVal)) {
 // The associated entity $newVal is not yet persisted, so we must
 // set $newVal = null, in order to insert a null value and schedule an
 // extra update on the UnitOfWork.
 $uow->scheduleExtraUpdate($entity, [$field => [null, $newVal]]);
 $newVal = null;
 }
 }
 $newValId = null;
 if ($newVal !== null) {
 $newValId = $uow->getEntityIdentifier($newVal);
 }
 $targetClass = $this->em->getClassMetadata($assoc['targetEntity']);
 $owningTable = $this->getOwningTable($field);
 foreach ($assoc['joinColumns'] as $joinColumn) {
 $sourceColumn = $joinColumn['name'];
 $targetColumn = $joinColumn['referencedColumnName'];
 $quotedColumn = $this->quoteStrategy->getJoinColumnName($joinColumn, $this->class, $this->platform);
 $this->quotedColumns[$sourceColumn] = $quotedColumn;
 $this->columnTypes[$sourceColumn] = PersisterHelper::getTypeOfColumn($targetColumn, $targetClass, $this->em);
 $result[$owningTable][$sourceColumn] = $newValId ? $newValId[$targetClass->getFieldForColumn($targetColumn)] : null;
 }
 }
 return $result;
 }
 protected function prepareInsertData($entity)
 {
 return $this->prepareUpdateData($entity, \true);
 }
 public function getOwningTable($fieldName)
 {
 return $this->class->getTableName();
 }
 public function load(array $criteria, $entity = null, $assoc = null, array $hints = [], $lockMode = null, $limit = null, ?array $orderBy = null)
 {
 $this->switchPersisterContext(null, $limit);
 $sql = $this->getSelectSQL($criteria, $assoc, $lockMode, $limit, null, $orderBy);
 [$params, $types] = $this->expandParameters($criteria);
 $stmt = $this->conn->executeQuery($sql, $params, $types);
 if ($entity !== null) {
 $hints[Query::HINT_REFRESH] = \true;
 $hints[Query::HINT_REFRESH_ENTITY] = $entity;
 }
 $hydrator = $this->em->newHydrator($this->currentPersisterContext->selectJoinSql ? Query::HYDRATE_OBJECT : Query::HYDRATE_SIMPLEOBJECT);
 $entities = $hydrator->hydrateAll($stmt, $this->currentPersisterContext->rsm, $hints);
 return $entities ? $entities[0] : null;
 }
 public function loadById(array $identifier, $entity = null)
 {
 return $this->load($identifier, $entity);
 }
 public function loadOneToOneEntity(array $assoc, $sourceEntity, array $identifier = [])
 {
 $foundEntity = $this->em->getUnitOfWork()->tryGetById($identifier, $assoc['targetEntity']);
 if ($foundEntity !== \false) {
 return $foundEntity;
 }
 $targetClass = $this->em->getClassMetadata($assoc['targetEntity']);
 if ($assoc['isOwningSide']) {
 $isInverseSingleValued = $assoc['inversedBy'] && !$targetClass->isCollectionValuedAssociation($assoc['inversedBy']);
 // Mark inverse side as fetched in the hints, otherwise the UoW would
 // try to load it in a separate query (remember: to-one inverse sides can not be lazy).
 $hints = [];
 if ($isInverseSingleValued) {
 $hints['fetched']['r'][$assoc['inversedBy']] = \true;
 }
 $targetEntity = $this->load($identifier, null, $assoc, $hints);
 // Complete bidirectional association, if necessary
 if ($targetEntity !== null && $isInverseSingleValued) {
 $targetClass->reflFields[$assoc['inversedBy']]->setValue($targetEntity, $sourceEntity);
 }
 return $targetEntity;
 }
 $sourceClass = $this->em->getClassMetadata($assoc['sourceEntity']);
 $owningAssoc = $targetClass->getAssociationMapping($assoc['mappedBy']);
 $computedIdentifier = [];
 // TRICKY: since the association is specular source and target are flipped
 foreach ($owningAssoc['targetToSourceKeyColumns'] as $sourceKeyColumn => $targetKeyColumn) {
 if (!isset($sourceClass->fieldNames[$sourceKeyColumn])) {
 throw MappingException::joinColumnMustPointToMappedField($sourceClass->name, $sourceKeyColumn);
 }
 $computedIdentifier[$targetClass->getFieldForColumn($targetKeyColumn)] = $sourceClass->reflFields[$sourceClass->fieldNames[$sourceKeyColumn]]->getValue($sourceEntity);
 }
 $targetEntity = $this->load($computedIdentifier, null, $assoc);
 if ($targetEntity !== null) {
 $targetClass->setFieldValue($targetEntity, $assoc['mappedBy'], $sourceEntity);
 }
 return $targetEntity;
 }
 public function refresh(array $id, $entity, $lockMode = null)
 {
 $sql = $this->getSelectSQL($id, null, $lockMode);
 [$params, $types] = $this->expandParameters($id);
 $stmt = $this->conn->executeQuery($sql, $params, $types);
 $hydrator = $this->em->newHydrator(Query::HYDRATE_OBJECT);
 $hydrator->hydrateAll($stmt, $this->currentPersisterContext->rsm, [Query::HINT_REFRESH => \true]);
 }
 public function count($criteria = [])
 {
 $sql = $this->getCountSQL($criteria);
 [$params, $types] = $criteria instanceof Criteria ? $this->expandCriteriaParameters($criteria) : $this->expandParameters($criteria);
 return (int) $this->conn->executeQuery($sql, $params, $types)->fetchOne();
 }
 public function loadCriteria(Criteria $criteria)
 {
 $orderBy = $criteria->getOrderings();
 $limit = $criteria->getMaxResults();
 $offset = $criteria->getFirstResult();
 $query = $this->getSelectSQL($criteria, null, null, $limit, $offset, $orderBy);
 [$params, $types] = $this->expandCriteriaParameters($criteria);
 $stmt = $this->conn->executeQuery($query, $params, $types);
 $hydrator = $this->em->newHydrator($this->currentPersisterContext->selectJoinSql ? Query::HYDRATE_OBJECT : Query::HYDRATE_SIMPLEOBJECT);
 return $hydrator->hydrateAll($stmt, $this->currentPersisterContext->rsm, [UnitOfWork::HINT_DEFEREAGERLOAD => \true]);
 }
 public function expandCriteriaParameters(Criteria $criteria)
 {
 $expression = $criteria->getWhereExpression();
 $sqlParams = [];
 $sqlTypes = [];
 if ($expression === null) {
 return [$sqlParams, $sqlTypes];
 }
 $valueVisitor = new SqlValueVisitor();
 $valueVisitor->dispatch($expression);
 [, $types] = $valueVisitor->getParamsAndTypes();
 foreach ($types as $type) {
 [$field, $value, $operator] = $type;
 if ($value === null && ($operator === Comparison::EQ || $operator === Comparison::NEQ)) {
 continue;
 }
 $sqlParams = array_merge($sqlParams, $this->getValues($value));
 $sqlTypes = array_merge($sqlTypes, $this->getTypes($field, $value, $this->class));
 }
 return [$sqlParams, $sqlTypes];
 }
 public function loadAll(array $criteria = [], ?array $orderBy = null, $limit = null, $offset = null)
 {
 $this->switchPersisterContext($offset, $limit);
 $sql = $this->getSelectSQL($criteria, null, null, $limit, $offset, $orderBy);
 [$params, $types] = $this->expandParameters($criteria);
 $stmt = $this->conn->executeQuery($sql, $params, $types);
 $hydrator = $this->em->newHydrator($this->currentPersisterContext->selectJoinSql ? Query::HYDRATE_OBJECT : Query::HYDRATE_SIMPLEOBJECT);
 return $hydrator->hydrateAll($stmt, $this->currentPersisterContext->rsm, [UnitOfWork::HINT_DEFEREAGERLOAD => \true]);
 }
 public function getManyToManyCollection(array $assoc, $sourceEntity, $offset = null, $limit = null)
 {
 $this->switchPersisterContext($offset, $limit);
 $stmt = $this->getManyToManyStatement($assoc, $sourceEntity, $offset, $limit);
 return $this->loadArrayFromResult($assoc, $stmt);
 }
 private function loadArrayFromResult(array $assoc, Result $stmt) : array
 {
 $rsm = $this->currentPersisterContext->rsm;
 $hints = [UnitOfWork::HINT_DEFEREAGERLOAD => \true];
 if (isset($assoc['indexBy'])) {
 $rsm = clone $this->currentPersisterContext->rsm;
 // this is necessary because the "default rsm" should be changed.
 $rsm->addIndexBy('r', $assoc['indexBy']);
 }
 return $this->em->newHydrator(Query::HYDRATE_OBJECT)->hydrateAll($stmt, $rsm, $hints);
 }
 private function loadCollectionFromStatement(array $assoc, Result $stmt, PersistentCollection $coll) : array
 {
 $rsm = $this->currentPersisterContext->rsm;
 $hints = [UnitOfWork::HINT_DEFEREAGERLOAD => \true, 'collection' => $coll];
 if (isset($assoc['indexBy'])) {
 $rsm = clone $this->currentPersisterContext->rsm;
 // this is necessary because the "default rsm" should be changed.
 $rsm->addIndexBy('r', $assoc['indexBy']);
 }
 return $this->em->newHydrator(Query::HYDRATE_OBJECT)->hydrateAll($stmt, $rsm, $hints);
 }
 public function loadManyToManyCollection(array $assoc, $sourceEntity, PersistentCollection $collection)
 {
 $stmt = $this->getManyToManyStatement($assoc, $sourceEntity);
 return $this->loadCollectionFromStatement($assoc, $stmt, $collection);
 }
 private function getManyToManyStatement(array $assoc, $sourceEntity, ?int $offset = null, ?int $limit = null)
 {
 $this->switchPersisterContext($offset, $limit);
 $sourceClass = $this->em->getClassMetadata($assoc['sourceEntity']);
 $class = $sourceClass;
 $association = $assoc;
 $criteria = [];
 $parameters = [];
 if (!$assoc['isOwningSide']) {
 $class = $this->em->getClassMetadata($assoc['targetEntity']);
 $association = $class->associationMappings[$assoc['mappedBy']];
 }
 $joinColumns = $assoc['isOwningSide'] ? $association['joinTable']['joinColumns'] : $association['joinTable']['inverseJoinColumns'];
 $quotedJoinTable = $this->quoteStrategy->getJoinTableName($association, $class, $this->platform);
 foreach ($joinColumns as $joinColumn) {
 $sourceKeyColumn = $joinColumn['referencedColumnName'];
 $quotedKeyColumn = $this->quoteStrategy->getJoinColumnName($joinColumn, $class, $this->platform);
 switch (\true) {
 case $sourceClass->containsForeignIdentifier:
 $field = $sourceClass->getFieldForColumn($sourceKeyColumn);
 $value = $sourceClass->reflFields[$field]->getValue($sourceEntity);
 if (isset($sourceClass->associationMappings[$field])) {
 $value = $this->em->getUnitOfWork()->getEntityIdentifier($value);
 $value = $value[$this->em->getClassMetadata($sourceClass->associationMappings[$field]['targetEntity'])->identifier[0]];
 }
 break;
 case isset($sourceClass->fieldNames[$sourceKeyColumn]):
 $field = $sourceClass->fieldNames[$sourceKeyColumn];
 $value = $sourceClass->reflFields[$field]->getValue($sourceEntity);
 break;
 default:
 throw MappingException::joinColumnMustPointToMappedField($sourceClass->name, $sourceKeyColumn);
 }
 $criteria[$quotedJoinTable . '.' . $quotedKeyColumn] = $value;
 $parameters[] = ['value' => $value, 'field' => $field, 'class' => $sourceClass];
 }
 $sql = $this->getSelectSQL($criteria, $assoc, null, $limit, $offset);
 [$params, $types] = $this->expandToManyParameters($parameters);
 return $this->conn->executeQuery($sql, $params, $types);
 }
 public function getSelectSQL($criteria, $assoc = null, $lockMode = null, $limit = null, $offset = null, ?array $orderBy = null)
 {
 $this->switchPersisterContext($offset, $limit);
 $lockSql = '';
 $joinSql = '';
 $orderBySql = '';
 if ($assoc !== null && $assoc['type'] === ClassMetadata::MANY_TO_MANY) {
 $joinSql = $this->getSelectManyToManyJoinSQL($assoc);
 }
 if (isset($assoc['orderBy'])) {
 $orderBy = $assoc['orderBy'];
 }
 if ($orderBy) {
 $orderBySql = $this->getOrderBySQL($orderBy, $this->getSQLTableAlias($this->class->name));
 }
 $conditionSql = $criteria instanceof Criteria ? $this->getSelectConditionCriteriaSQL($criteria) : $this->getSelectConditionSQL($criteria, $assoc);
 switch ($lockMode) {
 case LockMode::PESSIMISTIC_READ:
 $lockSql = ' ' . $this->platform->getReadLockSQL();
 break;
 case LockMode::PESSIMISTIC_WRITE:
 $lockSql = ' ' . $this->platform->getWriteLockSQL();
 break;
 }
 $columnList = $this->getSelectColumnsSQL();
 $tableAlias = $this->getSQLTableAlias($this->class->name);
 $filterSql = $this->generateFilterConditionSQL($this->class, $tableAlias);
 $tableName = $this->quoteStrategy->getTableName($this->class, $this->platform);
 if ($filterSql !== '') {
 $conditionSql = $conditionSql ? $conditionSql . ' AND ' . $filterSql : $filterSql;
 }
 $select = 'SELECT ' . $columnList;
 $from = ' FROM ' . $tableName . ' ' . $tableAlias;
 $join = $this->currentPersisterContext->selectJoinSql . $joinSql;
 $where = $conditionSql ? ' WHERE ' . $conditionSql : '';
 $lock = $this->platform->appendLockHint($from, $lockMode ?? LockMode::NONE);
 $query = $select . $lock . $join . $where . $orderBySql;
 return $this->platform->modifyLimitQuery($query, $limit, $offset ?? 0) . $lockSql;
 }
 public function getCountSQL($criteria = [])
 {
 $tableName = $this->quoteStrategy->getTableName($this->class, $this->platform);
 $tableAlias = $this->getSQLTableAlias($this->class->name);
 $conditionSql = $criteria instanceof Criteria ? $this->getSelectConditionCriteriaSQL($criteria) : $this->getSelectConditionSQL($criteria);
 $filterSql = $this->generateFilterConditionSQL($this->class, $tableAlias);
 if ($filterSql !== '') {
 $conditionSql = $conditionSql ? $conditionSql . ' AND ' . $filterSql : $filterSql;
 }
 return 'SELECT COUNT(*) ' . 'FROM ' . $tableName . ' ' . $tableAlias . (empty($conditionSql) ? '' : ' WHERE ' . $conditionSql);
 }
 protected final function getOrderBySQL(array $orderBy, string $baseTableAlias) : string
 {
 $orderByList = [];
 foreach ($orderBy as $fieldName => $orientation) {
 $orientation = strtoupper(trim($orientation));
 if ($orientation !== 'ASC' && $orientation !== 'DESC') {
 throw InvalidOrientation::fromClassNameAndField($this->class->name, $fieldName);
 }
 if (isset($this->class->fieldMappings[$fieldName])) {
 $tableAlias = isset($this->class->fieldMappings[$fieldName]['inherited']) ? $this->getSQLTableAlias($this->class->fieldMappings[$fieldName]['inherited']) : $baseTableAlias;
 $columnName = $this->quoteStrategy->getColumnName($fieldName, $this->class, $this->platform);
 $orderByList[] = $tableAlias . '.' . $columnName . ' ' . $orientation;
 continue;
 }
 if (isset($this->class->associationMappings[$fieldName])) {
 if (!$this->class->associationMappings[$fieldName]['isOwningSide']) {
 throw InvalidFindByCall::fromInverseSideUsage($this->class->name, $fieldName);
 }
 $tableAlias = isset($this->class->associationMappings[$fieldName]['inherited']) ? $this->getSQLTableAlias($this->class->associationMappings[$fieldName]['inherited']) : $baseTableAlias;
 foreach ($this->class->associationMappings[$fieldName]['joinColumns'] as $joinColumn) {
 $columnName = $this->quoteStrategy->getJoinColumnName($joinColumn, $this->class, $this->platform);
 $orderByList[] = $tableAlias . '.' . $columnName . ' ' . $orientation;
 }
 continue;
 }
 throw UnrecognizedField::byFullyQualifiedName($this->class->name, $fieldName);
 }
 return ' ORDER BY ' . implode(', ', $orderByList);
 }
 protected function getSelectColumnsSQL()
 {
 if ($this->currentPersisterContext->selectColumnListSql !== null) {
 return $this->currentPersisterContext->selectColumnListSql;
 }
 $columnList = [];
 $this->currentPersisterContext->rsm->addEntityResult($this->class->name, 'r');
 // r for root
 // Add regular columns to select list
 foreach ($this->class->fieldNames as $field) {
 $columnList[] = $this->getSelectColumnSQL($field, $this->class);
 }
 $this->currentPersisterContext->selectJoinSql = '';
 $eagerAliasCounter = 0;
 foreach ($this->class->associationMappings as $assocField => $assoc) {
 $assocColumnSQL = $this->getSelectColumnAssociationSQL($assocField, $assoc, $this->class);
 if ($assocColumnSQL) {
 $columnList[] = $assocColumnSQL;
 }
 $isAssocToOneInverseSide = $assoc['type'] & ClassMetadata::TO_ONE && !$assoc['isOwningSide'];
 $isAssocFromOneEager = $assoc['type'] !== ClassMetadata::MANY_TO_MANY && $assoc['fetch'] === ClassMetadata::FETCH_EAGER;
 if (!($isAssocFromOneEager || $isAssocToOneInverseSide)) {
 continue;
 }
 if (($assoc['type'] & ClassMetadata::TO_MANY) > 0 && $this->currentPersisterContext->handlesLimits) {
 continue;
 }
 $eagerEntity = $this->em->getClassMetadata($assoc['targetEntity']);
 if ($eagerEntity->inheritanceType !== ClassMetadata::INHERITANCE_TYPE_NONE) {
 continue;
 // now this is why you shouldn't use inheritance
 }
 $assocAlias = 'e' . $eagerAliasCounter++;
 $this->currentPersisterContext->rsm->addJoinedEntityResult($assoc['targetEntity'], $assocAlias, 'r', $assocField);
 foreach ($eagerEntity->fieldNames as $field) {
 $columnList[] = $this->getSelectColumnSQL($field, $eagerEntity, $assocAlias);
 }
 foreach ($eagerEntity->associationMappings as $eagerAssocField => $eagerAssoc) {
 $eagerAssocColumnSQL = $this->getSelectColumnAssociationSQL($eagerAssocField, $eagerAssoc, $eagerEntity, $assocAlias);
 if ($eagerAssocColumnSQL) {
 $columnList[] = $eagerAssocColumnSQL;
 }
 }
 $association = $assoc;
 $joinCondition = [];
 if (isset($assoc['indexBy'])) {
 $this->currentPersisterContext->rsm->addIndexBy($assocAlias, $assoc['indexBy']);
 }
 if (!$assoc['isOwningSide']) {
 $eagerEntity = $this->em->getClassMetadata($assoc['targetEntity']);
 $association = $eagerEntity->getAssociationMapping($assoc['mappedBy']);
 }
 $joinTableAlias = $this->getSQLTableAlias($eagerEntity->name, $assocAlias);
 $joinTableName = $this->quoteStrategy->getTableName($eagerEntity, $this->platform);
 if ($assoc['isOwningSide']) {
 $tableAlias = $this->getSQLTableAlias($association['targetEntity'], $assocAlias);
 $this->currentPersisterContext->selectJoinSql .= ' ' . $this->getJoinSQLForJoinColumns($association['joinColumns']);
 foreach ($association['joinColumns'] as $joinColumn) {
 $sourceCol = $this->quoteStrategy->getJoinColumnName($joinColumn, $this->class, $this->platform);
 $targetCol = $this->quoteStrategy->getReferencedJoinColumnName($joinColumn, $this->class, $this->platform);
 $joinCondition[] = $this->getSQLTableAlias($association['sourceEntity']) . '.' . $sourceCol . ' = ' . $tableAlias . '.' . $targetCol;
 }
 // Add filter SQL
 $filterSql = $this->generateFilterConditionSQL($eagerEntity, $tableAlias);
 if ($filterSql) {
 $joinCondition[] = $filterSql;
 }
 } else {
 $this->currentPersisterContext->selectJoinSql .= ' LEFT JOIN';
 foreach ($association['joinColumns'] as $joinColumn) {
 $sourceCol = $this->quoteStrategy->getJoinColumnName($joinColumn, $this->class, $this->platform);
 $targetCol = $this->quoteStrategy->getReferencedJoinColumnName($joinColumn, $this->class, $this->platform);
 $joinCondition[] = $this->getSQLTableAlias($association['sourceEntity'], $assocAlias) . '.' . $sourceCol . ' = ' . $this->getSQLTableAlias($association['targetEntity']) . '.' . $targetCol;
 }
 }
 $this->currentPersisterContext->selectJoinSql .= ' ' . $joinTableName . ' ' . $joinTableAlias . ' ON ';
 $this->currentPersisterContext->selectJoinSql .= implode(' AND ', $joinCondition);
 }
 $this->currentPersisterContext->selectColumnListSql = implode(', ', $columnList);
 return $this->currentPersisterContext->selectColumnListSql;
 }
 protected function getSelectColumnAssociationSQL($field, $assoc, ClassMetadata $class, $alias = 'r')
 {
 if (!($assoc['isOwningSide'] && $assoc['type'] & ClassMetadata::TO_ONE)) {
 return '';
 }
 $columnList = [];
 $targetClass = $this->em->getClassMetadata($assoc['targetEntity']);
 $isIdentifier = isset($assoc['id']) && $assoc['id'] === \true;
 $sqlTableAlias = $this->getSQLTableAlias($class->name, $alias === 'r' ? '' : $alias);
 foreach ($assoc['joinColumns'] as $joinColumn) {
 $quotedColumn = $this->quoteStrategy->getJoinColumnName($joinColumn, $this->class, $this->platform);
 $resultColumnName = $this->getSQLColumnAlias($joinColumn['name']);
 $type = PersisterHelper::getTypeOfColumn($joinColumn['referencedColumnName'], $targetClass, $this->em);
 $this->currentPersisterContext->rsm->addMetaResult($alias, $resultColumnName, $joinColumn['name'], $isIdentifier, $type);
 $columnList[] = sprintf('%s.%s AS %s', $sqlTableAlias, $quotedColumn, $resultColumnName);
 }
 return implode(', ', $columnList);
 }
 protected function getSelectManyToManyJoinSQL(array $manyToMany)
 {
 $conditions = [];
 $association = $manyToMany;
 $sourceTableAlias = $this->getSQLTableAlias($this->class->name);
 if (!$manyToMany['isOwningSide']) {
 $targetEntity = $this->em->getClassMetadata($manyToMany['targetEntity']);
 $association = $targetEntity->associationMappings[$manyToMany['mappedBy']];
 }
 $joinTableName = $this->quoteStrategy->getJoinTableName($association, $this->class, $this->platform);
 $joinColumns = $manyToMany['isOwningSide'] ? $association['joinTable']['inverseJoinColumns'] : $association['joinTable']['joinColumns'];
 foreach ($joinColumns as $joinColumn) {
 $quotedSourceColumn = $this->quoteStrategy->getJoinColumnName($joinColumn, $this->class, $this->platform);
 $quotedTargetColumn = $this->quoteStrategy->getReferencedJoinColumnName($joinColumn, $this->class, $this->platform);
 $conditions[] = $sourceTableAlias . '.' . $quotedTargetColumn . ' = ' . $joinTableName . '.' . $quotedSourceColumn;
 }
 return ' INNER JOIN ' . $joinTableName . ' ON ' . implode(' AND ', $conditions);
 }
 public function getInsertSQL()
 {
 if ($this->insertSql !== null) {
 return $this->insertSql;
 }
 $columns = $this->getInsertColumnList();
 $tableName = $this->quoteStrategy->getTableName($this->class, $this->platform);
 if (empty($columns)) {
 $identityColumn = $this->quoteStrategy->getColumnName($this->class->identifier[0], $this->class, $this->platform);
 $this->insertSql = $this->platform->getEmptyIdentityInsertSQL($tableName, $identityColumn);
 return $this->insertSql;
 }
 $values = [];
 $columns = array_unique($columns);
 foreach ($columns as $column) {
 $placeholder = '?';
 if (isset($this->class->fieldNames[$column]) && isset($this->columnTypes[$this->class->fieldNames[$column]]) && isset($this->class->fieldMappings[$this->class->fieldNames[$column]]['requireSQLConversion'])) {
 $type = Type::getType($this->columnTypes[$this->class->fieldNames[$column]]);
 $placeholder = $type->convertToDatabaseValueSQL('?', $this->platform);
 }
 $values[] = $placeholder;
 }
 $columns = implode(', ', $columns);
 $values = implode(', ', $values);
 $this->insertSql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $tableName, $columns, $values);
 return $this->insertSql;
 }
 protected function getInsertColumnList()
 {
 $columns = [];
 foreach ($this->class->reflFields as $name => $field) {
 if ($this->class->isVersioned && $this->class->versionField === $name) {
 continue;
 }
 if (isset($this->class->embeddedClasses[$name])) {
 continue;
 }
 if (isset($this->class->associationMappings[$name])) {
 $assoc = $this->class->associationMappings[$name];
 if ($assoc['isOwningSide'] && $assoc['type'] & ClassMetadata::TO_ONE) {
 foreach ($assoc['joinColumns'] as $joinColumn) {
 $columns[] = $this->quoteStrategy->getJoinColumnName($joinColumn, $this->class, $this->platform);
 }
 }
 continue;
 }
 if (!$this->class->isIdGeneratorIdentity() || $this->class->identifier[0] !== $name) {
 if (isset($this->class->fieldMappings[$name]['notInsertable'])) {
 continue;
 }
 $columns[] = $this->quoteStrategy->getColumnName($name, $this->class, $this->platform);
 $this->columnTypes[$name] = $this->class->fieldMappings[$name]['type'];
 }
 }
 return $columns;
 }
 protected function getSelectColumnSQL($field, ClassMetadata $class, $alias = 'r')
 {
 $root = $alias === 'r' ? '' : $alias;
 $tableAlias = $this->getSQLTableAlias($class->name, $root);
 $fieldMapping = $class->fieldMappings[$field];
 $sql = sprintf('%s.%s', $tableAlias, $this->quoteStrategy->getColumnName($field, $class, $this->platform));
 $columnAlias = $this->getSQLColumnAlias($fieldMapping['columnName']);
 $this->currentPersisterContext->rsm->addFieldResult($alias, $columnAlias, $field);
 if (!empty($fieldMapping['enumType'])) {
 $this->currentPersisterContext->rsm->addEnumResult($columnAlias, $fieldMapping['enumType']);
 }
 if (isset($fieldMapping['requireSQLConversion'])) {
 $type = Type::getType($fieldMapping['type']);
 $sql = $type->convertToPHPValueSQL($sql, $this->platform);
 }
 return $sql . ' AS ' . $columnAlias;
 }
 protected function getSQLTableAlias($className, $assocName = '')
 {
 if ($assocName) {
 $className .= '#' . $assocName;
 }
 if (isset($this->currentPersisterContext->sqlTableAliases[$className])) {
 return $this->currentPersisterContext->sqlTableAliases[$className];
 }
 $tableAlias = 't' . $this->currentPersisterContext->sqlAliasCounter++;
 $this->currentPersisterContext->sqlTableAliases[$className] = $tableAlias;
 return $tableAlias;
 }
 public function lock(array $criteria, $lockMode)
 {
 $lockSql = '';
 $conditionSql = $this->getSelectConditionSQL($criteria);
 switch ($lockMode) {
 case LockMode::PESSIMISTIC_READ:
 $lockSql = $this->platform->getReadLockSQL();
 break;
 case LockMode::PESSIMISTIC_WRITE:
 $lockSql = $this->platform->getWriteLockSQL();
 break;
 }
 $lock = $this->getLockTablesSql($lockMode);
 $where = ($conditionSql ? ' WHERE ' . $conditionSql : '') . ' ';
 $sql = 'SELECT 1 ' . $lock . $where . $lockSql;
 [$params, $types] = $this->expandParameters($criteria);
 $this->conn->executeQuery($sql, $params, $types);
 }
 protected function getLockTablesSql($lockMode)
 {
 if ($lockMode === null) {
 Deprecation::trigger('doctrine/orm', 'https://github.com/doctrine/orm/pull/9466', 'Passing null as argument to %s is deprecated, pass LockMode::NONE instead.', __METHOD__);
 $lockMode = LockMode::NONE;
 }
 return $this->platform->appendLockHint('FROM ' . $this->quoteStrategy->getTableName($this->class, $this->platform) . ' ' . $this->getSQLTableAlias($this->class->name), $lockMode);
 }
 protected function getSelectConditionCriteriaSQL(Criteria $criteria)
 {
 $expression = $criteria->getWhereExpression();
 if ($expression === null) {
 return '';
 }
 $visitor = new SqlExpressionVisitor($this, $this->class);
 return $visitor->dispatch($expression);
 }
 public function getSelectConditionStatementSQL($field, $value, $assoc = null, $comparison = null)
 {
 $selectedColumns = [];
 $columns = $this->getSelectConditionStatementColumnSQL($field, $assoc);
 if (count($columns) > 1 && $comparison === Comparison::IN) {
 throw CantUseInOperatorOnCompositeKeys::create();
 }
 foreach ($columns as $column) {
 $placeholder = '?';
 if (isset($this->class->fieldMappings[$field]['requireSQLConversion'])) {
 $type = Type::getType($this->class->fieldMappings[$field]['type']);
 $placeholder = $type->convertToDatabaseValueSQL($placeholder, $this->platform);
 }
 if ($comparison !== null) {
 // special case null value handling
 if (($comparison === Comparison::EQ || $comparison === Comparison::IS) && $value === null) {
 $selectedColumns[] = $column . ' IS NULL';
 continue;
 }
 if ($comparison === Comparison::NEQ && $value === null) {
 $selectedColumns[] = $column . ' IS NOT NULL';
 continue;
 }
 $selectedColumns[] = $column . ' ' . sprintf(self::$comparisonMap[$comparison], $placeholder);
 continue;
 }
 if (is_array($value)) {
 $in = sprintf('%s IN (%s)', $column, $placeholder);
 if (array_search(null, $value, \true) !== \false) {
 $selectedColumns[] = sprintf('(%s OR %s IS NULL)', $in, $column);
 continue;
 }
 $selectedColumns[] = $in;
 continue;
 }
 if ($value === null) {
 $selectedColumns[] = sprintf('%s IS NULL', $column);
 continue;
 }
 $selectedColumns[] = sprintf('%s = %s', $column, $placeholder);
 }
 return implode(' AND ', $selectedColumns);
 }
 private function getSelectConditionStatementColumnSQL(string $field, ?array $assoc = null) : array
 {
 if (isset($this->class->fieldMappings[$field])) {
 $className = $this->class->fieldMappings[$field]['inherited'] ?? $this->class->name;
 return [$this->getSQLTableAlias($className) . '.' . $this->quoteStrategy->getColumnName($field, $this->class, $this->platform)];
 }
 if (isset($this->class->associationMappings[$field])) {
 $association = $this->class->associationMappings[$field];
 // Many-To-Many requires join table check for joinColumn
 $columns = [];
 $class = $this->class;
 if ($association['type'] === ClassMetadata::MANY_TO_MANY) {
 if (!$association['isOwningSide']) {
 $association = $assoc;
 }
 $joinTableName = $this->quoteStrategy->getJoinTableName($association, $class, $this->platform);
 $joinColumns = $assoc['isOwningSide'] ? $association['joinTable']['joinColumns'] : $association['joinTable']['inverseJoinColumns'];
 foreach ($joinColumns as $joinColumn) {
 $columns[] = $joinTableName . '.' . $this->quoteStrategy->getJoinColumnName($joinColumn, $class, $this->platform);
 }
 } else {
 if (!$association['isOwningSide']) {
 throw InvalidFindByCall::fromInverseSideUsage($this->class->name, $field);
 }
 $className = $association['inherited'] ?? $this->class->name;
 foreach ($association['joinColumns'] as $joinColumn) {
 $columns[] = $this->getSQLTableAlias($className) . '.' . $this->quoteStrategy->getJoinColumnName($joinColumn, $this->class, $this->platform);
 }
 }
 return $columns;
 }
 if ($assoc !== null && !str_contains($field, ' ') && !str_contains($field, '(')) {
 // very careless developers could potentially open up this normally hidden api for userland attacks,
 // therefore checking for spaces and function calls which are not allowed.
 // found a join column condition, not really a "field"
 return [$field];
 }
 throw UnrecognizedField::byFullyQualifiedName($this->class->name, $field);
 }
 protected function getSelectConditionSQL(array $criteria, $assoc = null)
 {
 $conditions = [];
 foreach ($criteria as $field => $value) {
 $conditions[] = $this->getSelectConditionStatementSQL($field, $value, $assoc);
 }
 return implode(' AND ', $conditions);
 }
 public function getOneToManyCollection(array $assoc, $sourceEntity, $offset = null, $limit = null)
 {
 $this->switchPersisterContext($offset, $limit);
 $stmt = $this->getOneToManyStatement($assoc, $sourceEntity, $offset, $limit);
 return $this->loadArrayFromResult($assoc, $stmt);
 }
 public function loadOneToManyCollection(array $assoc, $sourceEntity, PersistentCollection $collection)
 {
 $stmt = $this->getOneToManyStatement($assoc, $sourceEntity);
 return $this->loadCollectionFromStatement($assoc, $stmt, $collection);
 }
 private function getOneToManyStatement(array $assoc, $sourceEntity, ?int $offset = null, ?int $limit = null) : Result
 {
 $this->switchPersisterContext($offset, $limit);
 $criteria = [];
 $parameters = [];
 $owningAssoc = $this->class->associationMappings[$assoc['mappedBy']];
 $sourceClass = $this->em->getClassMetadata($assoc['sourceEntity']);
 $tableAlias = $this->getSQLTableAlias($owningAssoc['inherited'] ?? $this->class->name);
 foreach ($owningAssoc['targetToSourceKeyColumns'] as $sourceKeyColumn => $targetKeyColumn) {
 if ($sourceClass->containsForeignIdentifier) {
 $field = $sourceClass->getFieldForColumn($sourceKeyColumn);
 $value = $sourceClass->reflFields[$field]->getValue($sourceEntity);
 if (isset($sourceClass->associationMappings[$field])) {
 $value = $this->em->getUnitOfWork()->getEntityIdentifier($value);
 $value = $value[$this->em->getClassMetadata($sourceClass->associationMappings[$field]['targetEntity'])->identifier[0]];
 }
 $criteria[$tableAlias . '.' . $targetKeyColumn] = $value;
 $parameters[] = ['value' => $value, 'field' => $field, 'class' => $sourceClass];
 continue;
 }
 $field = $sourceClass->fieldNames[$sourceKeyColumn];
 $value = $sourceClass->reflFields[$field]->getValue($sourceEntity);
 $criteria[$tableAlias . '.' . $targetKeyColumn] = $value;
 $parameters[] = ['value' => $value, 'field' => $field, 'class' => $sourceClass];
 }
 $sql = $this->getSelectSQL($criteria, $assoc, null, $limit, $offset);
 [$params, $types] = $this->expandToManyParameters($parameters);
 return $this->conn->executeQuery($sql, $params, $types);
 }
 public function expandParameters($criteria)
 {
 $params = [];
 $types = [];
 foreach ($criteria as $field => $value) {
 if ($value === null) {
 continue;
 // skip null values.
 }
 $types = array_merge($types, $this->getTypes($field, $value, $this->class));
 $params = array_merge($params, $this->getValues($value));
 }
 return [$params, $types];
 }
 private function expandToManyParameters(array $criteria) : array
 {
 $params = [];
 $types = [];
 foreach ($criteria as $criterion) {
 if ($criterion['value'] === null) {
 continue;
 // skip null values.
 }
 $types = array_merge($types, $this->getTypes($criterion['field'], $criterion['value'], $criterion['class']));
 $params = array_merge($params, $this->getValues($criterion['value']));
 }
 return [$params, $types];
 }
 private function getTypes(string $field, $value, ClassMetadata $class) : array
 {
 $types = [];
 switch (\true) {
 case isset($class->fieldMappings[$field]):
 $types = array_merge($types, [$class->fieldMappings[$field]['type']]);
 break;
 case isset($class->associationMappings[$field]):
 $assoc = $class->associationMappings[$field];
 $class = $this->em->getClassMetadata($assoc['targetEntity']);
 if (!$assoc['isOwningSide']) {
 $assoc = $class->associationMappings[$assoc['mappedBy']];
 $class = $this->em->getClassMetadata($assoc['targetEntity']);
 }
 $columns = $assoc['type'] === ClassMetadata::MANY_TO_MANY ? $assoc['relationToTargetKeyColumns'] : $assoc['sourceToTargetKeyColumns'];
 foreach ($columns as $column) {
 $types[] = PersisterHelper::getTypeOfColumn($column, $class, $this->em);
 }
 break;
 default:
 $types[] = null;
 break;
 }
 if (is_array($value)) {
 return array_map(static function ($type) {
 $type = Type::getType($type);
 return $type->getBindingType() + Connection::ARRAY_PARAM_OFFSET;
 }, $types);
 }
 return $types;
 }
 private function getValues($value) : array
 {
 if (is_array($value)) {
 $newValue = [];
 foreach ($value as $itemValue) {
 $newValue = array_merge($newValue, $this->getValues($itemValue));
 }
 return [$newValue];
 }
 return $this->getIndividualValue($value);
 }
 private function getIndividualValue($value) : array
 {
 if (!is_object($value)) {
 return [$value];
 }
 if ($value instanceof BackedEnum) {
 return [$value->value];
 }
 $valueClass = ClassUtils::getClass($value);
 if ($this->em->getMetadataFactory()->isTransient($valueClass)) {
 return [$value];
 }
 $class = $this->em->getClassMetadata($valueClass);
 if ($class->isIdentifierComposite) {
 $newValue = [];
 foreach ($class->getIdentifierValues($value) as $innerValue) {
 $newValue = array_merge($newValue, $this->getValues($innerValue));
 }
 return $newValue;
 }
 return [$this->em->getUnitOfWork()->getSingleIdentifierValue($value)];
 }
 public function exists($entity, ?Criteria $extraConditions = null)
 {
 $criteria = $this->class->getIdentifierValues($entity);
 if (!$criteria) {
 return \false;
 }
 $alias = $this->getSQLTableAlias($this->class->name);
 $sql = 'SELECT 1 ' . $this->getLockTablesSql(LockMode::NONE) . ' WHERE ' . $this->getSelectConditionSQL($criteria);
 [$params, $types] = $this->expandParameters($criteria);
 if ($extraConditions !== null) {
 $sql .= ' AND ' . $this->getSelectConditionCriteriaSQL($extraConditions);
 [$criteriaParams, $criteriaTypes] = $this->expandCriteriaParameters($extraConditions);
 $params = array_merge($params, $criteriaParams);
 $types = array_merge($types, $criteriaTypes);
 }
 $filterSql = $this->generateFilterConditionSQL($this->class, $alias);
 if ($filterSql) {
 $sql .= ' AND ' . $filterSql;
 }
 return (bool) $this->conn->fetchOne($sql, $params, $types);
 }
 protected function getJoinSQLForJoinColumns($joinColumns)
 {
 // if one of the join columns is nullable, return left join
 foreach ($joinColumns as $joinColumn) {
 if (!isset($joinColumn['nullable']) || $joinColumn['nullable']) {
 return 'LEFT JOIN';
 }
 }
 return 'INNER JOIN';
 }
 public function getSQLColumnAlias($columnName)
 {
 return $this->quoteStrategy->getColumnAlias($columnName, $this->currentPersisterContext->sqlAliasCounter++, $this->platform);
 }
 protected function generateFilterConditionSQL(ClassMetadata $targetEntity, $targetTableAlias)
 {
 $filterClauses = [];
 foreach ($this->em->getFilters()->getEnabledFilters() as $filter) {
 $filterExpr = $filter->addFilterConstraint($targetEntity, $targetTableAlias);
 if ($filterExpr !== '') {
 $filterClauses[] = '(' . $filterExpr . ')';
 }
 }
 $sql = implode(' AND ', $filterClauses);
 return $sql ? '(' . $sql . ')' : '';
 // Wrap again to avoid "X or Y and FilterConditionSQL"
 }
 protected function switchPersisterContext($offset, $limit)
 {
 if ($offset === null && $limit === null) {
 $this->currentPersisterContext = $this->noLimitsContext;
 return;
 }
 $this->currentPersisterContext = $this->limitsHandlingContext;
 }
 protected function getClassIdentifiersTypes(ClassMetadata $class) : array
 {
 $entityManager = $this->em;
 return array_map(static function ($fieldName) use($class, $entityManager) : string {
 $types = PersisterHelper::getTypeOfField($fieldName, $class, $entityManager);
 assert(isset($types[0]));
 return $types[0];
 }, $class->identifier);
 }
}
