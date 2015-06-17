<?php
class kResponseProfileCacher implements kObjectChangedEventConsumer, kObjectDeletedEventConsumer, kObjectAddedEventConsumer
{
	const MAX_CACHE_KEYS_PER_JOB = 1000;
	
	/**
	 * @var array
	 */
	private static $cacheStores = null;
	
	/**
	 * @return array<kBaseCacheWrapper>
	 */
	protected static function getStores()
	{
		if(is_array(self::$cacheStores))
			return self::$cacheStores;
			
		self::$cacheStores = array();
		$cacheSections = kCacheManager::getCacheSectionNames(kCacheManager::CACHE_TYPE_RESPONSE_PROFILE);
		foreach ($cacheSections as $cacheSection)
		{
			$cacheStore = kCacheManager::getCache($cacheSection);
			if ($cacheStore)
				self::$cacheStores[] = $cacheStore;
		}
		
		return self::$cacheStores;
	}
	
	protected static function invalidateRelated(IBaseObject $object)
	{
		KalturaLog::debug('Invalidating object [' . get_class($object) . '] id [' . $object->getPrimaryKey() . '] related objects');
		
		$partnerId = $object->getPartnerId();
		$triggerKey = self::getRelatedObjectKey($object);
		$objectTypes = self::listObjectRelatedTypes($triggerKey);
		foreach($objectTypes as $objectType)
		{
			self::invalidate("{$partnerId}_{$objectType}");
		}
	}
	
	protected static function invalidate($invalidationKey)
	{
		$now = time();
		KalturaLog::debug("Invalidating key [$invalidationKey] now [$now]");
		self::set($invalidationKey, $now);
	}
	
	protected static function set($key, $value)
	{
		KalturaLog::debug("Key [$key]");
		
		$cacheStores = self::getStores();
		foreach ($cacheStores as $cacheStore)
		{
			/* @var $cacheStore kBaseCacheWrapper */
			$cacheStore->set($key, $value);
		}
	}
	
	protected static function delete($key)
	{
		KalturaLog::debug("Key [$key]");
		$cacheStores = self::getStores();
		foreach ($cacheStores as $cacheStore)
		{
			$cacheStore->delete($key);
		}
	}
	
	protected static function getMulti(array $keys)
	{
		$cacheStores = self::getStores();
		foreach ($cacheStores as $cacheStore)
		{
			if($cacheStore instanceof kCouchbaseCacheWrapper)
			{
				return $cacheStore->multiGetAndTouch($keys);
			}
			else
			{
				return $cacheStore->multiGet($keys);
			}
		}
		
		return false;
	}
	
	protected static function get($key, array $invalidationKeys = null, $touch = true)
	{
		KalturaLog::debug("Key [$key]");
		$cacheStores = self::getStores();
		$value = null;
		foreach ($cacheStores as $cacheStore)
		{
			if($touch && $cacheStore instanceof kCouchbaseCacheWrapper)
			{
				$value = $cacheStore->getAndTouch($key);
			}
			else
			{
				$value = $cacheStore->get($key);
			}
			
			if($value)
			{
				if($invalidationKeys)
				{
					$invalidationTimes = self::getMulti($invalidationKeys);
					if($invalidationTimes)
					{
						foreach($invalidationTimes as $invalidationKey => $invalidationTime)
						{
							if(!is_null($invalidationTime))
							{
								KalturaLog::debug("Invalidation key [$invalidationKey] time [$invalidationTime] compare to value time [{$value->time}]");
								if(intval($invalidationTime) >= intval($value->time))
								{
									KalturaLog::debug("Invalidation time [$invalidationTime] >= value time [{$value->time}]");
									return null;
								}
							}
						}
					}
				}
				
				return $value;
			}
		}
		
		return null;
	}
	
	protected static function query(kCouchbaseCacheQuery $query)
	{
		$cacheStores = self::getStores();
		foreach ($cacheStores as $cacheStore)
		{
			if($cacheStore instanceof kCouchbaseCacheWrapper)
			{
				return $cacheStore->query($query);
			}
		}
		
		return array();
	}

	protected static function getSessionKey($protocol = null, $ksType = null, array $userRoles = null)
	{
		if(!$protocol)
			$protocol = infraRequestUtils::getProtocol();
		if(!$ksType)
			$ksType = kCurrentContext::getCurrentSessionType();
		if(!$userRoles)
			$userRoles = kPermissionManager::getCurrentRoleIds();
			
		sort($userRoles);
		$userRole = implode('_', $userRoles);
		return "{$protocol}_{$ksType}_{$userRole}";
	}
	
	protected static function getObjectKey(IBaseObject $object)
	{
		$partnerId = $object->getPartnerId();
		$objectType = get_class($object);
		$objectId = $object->getPrimaryKey();
		return "{$partnerId}_{$objectType}_{$objectId}";
	}
	
	protected static function getRelatedObjectKey(IBaseObject $object)
	{
		$partnerId = $object->getPartnerId();
		$objectType = get_class($object);
		return "{$partnerId}_{$objectType}";
	}
	
	/* (non-PHPdoc)
	 * @see kObjectChangedEventConsumer::objectChanged()
	 */
	public function objectChanged(BaseObject $object, array $modifiedColumns)
	{
		if($object instanceof ResponseProfile)
			return $this->deleteResponseProfileCache($object);

		/* @var $object IBaseObject */
		if($this->isCachedObject($object))
		{
			KalturaLog::debug('Object [' . get_class($object) . '] id [' . $object->getPrimaryKey() . '] is cached object');
			$this->invalidateCachedObject($object);
		}
			
		if($this->hasCachedRootObjects($object))
		{
			KalturaLog::debug('Object [' . get_class($object) . '] id [' . $object->getPrimaryKey() . '] has cached root objects');
			$this->invalidateCachedRootObjects($object);
		}
			
		if($this->hasCachedRelatedObjects($object))
		{
			KalturaLog::debug('Object [' . get_class($object) . '] id [' . $object->getPrimaryKey() . '] has cached related objects');
			$this->invalidateCachedRelatedObjects($object);
		}
			
		return true;
	}
	
	/* (non-PHPdoc)
	 * @see kObjectChangedEventConsumer::shouldConsumeChangedEvent()
	 */
	public function shouldConsumeChangedEvent(BaseObject $object, array $modifiedColumns)
	{
		if($object instanceof ResponseProfile)
			return true;

		if($object instanceof IBaseObject)
		{
			if($this->hasCachedRelatedObjects($object))
				return true;
				
			if($this->hasCachedRootObjects($object))
				return true;
				
			if($this->isCachedObject($object))
				return true;
		}
			
		return false;
	}

	/* (non-PHPdoc)
	 * @see kObjectDeletedEventConsumer::objectDeleted()
	 */
	public function objectDeleted(BaseObject $object, BatchJob $raisedJob = null)
	{
		if($object instanceof ResponseProfile)
			return $this->deleteResponseProfileCache($object);

		/* @var $object IBaseObject */
		
		if($this->isCachedObject($object))
		{
			KalturaLog::debug('Object [' . get_class($object) . '] id [' . $object->getPrimaryKey() . '] is cached object');
			$this->invalidateCachedObject($object);
		}
					
		if($this->hasCachedRelatedObjects($object))
		{
			KalturaLog::debug('Object [' . get_class($object) . '] id [' . $object->getPrimaryKey() . '] has cached related objects');
			$this->invalidateCachedRelatedObjects($object);
		}
			
		if($this->hasCachedRootObjects($object))
		{
			KalturaLog::debug('Object [' . get_class($object) . '] id [' . $object->getPrimaryKey() . '] has cached root objects');
			$this->invalidateCachedRootObjects($object);
		}
			
		return true;
	}
	
	/* (non-PHPdoc)
	 * @see kObjectDeletedEventConsumer::shouldConsumeDeletedEvent()
	 */
	public function shouldConsumeDeletedEvent(BaseObject $object)
	{
		if($object instanceof ResponseProfile)
			return true;

		if($object instanceof IBaseObject)
		{
			if($this->hasCachedRelatedObjects($object))
				return true;
				
			if($this->hasCachedRootObjects($object))
				return true;
				
			if($this->isCachedObject($object))
				return true;
		}
			
		return false;
	}
	
	/* (non-PHPdoc)
	 * @see kObjectAddedEventConsumer::objectAdded()
	 */
	public function objectAdded(BaseObject $object, BatchJob $raisedJob = null)
	{
		/* @var $object IBaseObject */
		if($this->hasCachedRelatedObjects($object))
		{
			KalturaLog::debug('Object [' . get_class($object) . '] id [' . $object->getPrimaryKey() . '] has cached related objects');
			$this->invalidateCachedRelatedObjects($object);
		}
			
		if($this->hasCachedRootObjects($object))
		{
			KalturaLog::debug('Object [' . get_class($object) . '] id [' . $object->getPrimaryKey() . '] has cached root objects');
			$this->invalidateCachedRootObjects($object);
		}
			
		return true;
	}
	
	/* (non-PHPdoc)
	 * @see kObjectAddedEventConsumer::shouldConsumeAddedEvent()
	 */
	public function shouldConsumeAddedEvent(BaseObject $object)
	{
		if($object instanceof IBaseObject)
		{
			if($this->hasCachedRelatedObjects($object))
				return true;
				
			if($this->hasCachedRootObjects($object))
				return true;
		}
			
		return false;
	}
	
	protected function hasCachedRelatedObjects(IBaseObject $object)
	{
		$peer = $object->getPeer();
		if(!($peer instanceof IRelatedObjectPeer) || !$peer->isReferenced($object))
		{
			return false;
		}
		
		$cacheStores = self::getStores();
		foreach ($cacheStores as $cacheStore)
		{
			if($cacheStore instanceof kCouchbaseCacheWrapper)
			{
				$query = $cacheStore->getNewQuery(kCouchbaseCacheQuery::VIEW_RESPONSE_PROFILE_RELATED_OBJECT_SESSIONS);
				if($query)
				{
					$query->addStartKey('triggerKey', self::getRelatedObjectKey($object));
					$query->addStartKey('objectType', 'a');
					$query->addStartKey('sessionKey', 'a');
					$query->setLimit(1);
					
					$list = $cacheStore->query($query);
					if($list->getCount())
						return true;
				}
			}
		}
		
		return false;
	}
	
	protected function hasCachedRootObjects(IBaseObject $object)
	{
		$peer = $object->getPeer();
		if(!($peer instanceof IRelatedObjectPeer))
		{
			return false;
		}
		
		if($peer->isReferenced($object))
		{
			return false;
		}
		
		$roots = $peer->getRootObjects($object);
		if(count($roots))
			return true;
				
		return false;
	}
	
	protected function isCachedObject(IBaseObject $object)
	{
		$cacheStores = self::getStores();
		foreach ($cacheStores as $cacheStore)
		{
			if($cacheStore instanceof kCouchbaseCacheWrapper)
			{
				$query = $cacheStore->getNewQuery(kCouchbaseCacheQuery::VIEW_RESPONSE_PROFILE_OBJECT_SPECIFIC);
				if($query)
				{
					$query->setKey(self::getObjectKey($object));
					$query->setLimit(1);
					
					$list = $cacheStore->query($query);
					if($list->getCount())
						return true;
				}
			}
		}
		
		return false;
	}
	
	protected function deleteResponseProfileCache(ResponseProfile $responseProfile)
	{
		$key = self::getResponseProfileCacheKey($responseProfile->getKey());
		self::delete($key);
		
		return true;
	}
	
	protected function listDocIds($objectType, $sessionKey)
	{
		$cacheStores = self::getStores();
		foreach ($cacheStores as $cacheStore)
		{
			if($cacheStore instanceof kCouchbaseCacheWrapper)
			{
				// TODO optimize using elastic search query
				$query = $cacheStore->getNewQuery(kCouchbaseCacheQuery::VIEW_RESPONSE_PROFILE_OBJECT_TYPE_SESSIONS);
				if($query)
				{
					$query->addStartKey('objectType', $objectType);
					$query->addStartKey('sessionKey', $sessionKey);
					$query->addStartKey('objectId', '0');
					$query->addEndKey('objectType', $objectType);
					$query->addEndKey('sessionKey', $sessionKey);
					$query->addEndKey('objectId', 'Z');
					$query->setLimit(1);

					$list = $cacheStore->query($query);
					$query->setLimit(2);
					$offset = -1;
					$array = array();
					$startId = null;
					while(count($list->getObjects()))
					{
						$objects = $list->getObjects();
						if(count($objects) == 1)
						{
							$startCacheObject = reset($objects);
							/* @var $startCacheObject kCouchbaseCacheListItem */
							$startId = $startCacheObject->getId();
						}
						elseif(count($objects) == 2)
						{
							list($endCacheObject, $startCacheObject) = $objects;
							/* @var $endCacheObject kCouchbaseCacheListItem */
							/* @var $startCacheObject kCouchbaseCacheListItem */
							$array[] = array($startId, $endCacheObject->getId());
							$startId = $startCacheObject->getId();
						}
						$offset += self::MAX_CACHE_KEYS_PER_JOB;
						$query->setOffset($offset);
						$list = $cacheStore->query($query);
					}
					return $array;
				}
			}
		}
	
		return array();
	}
	
	protected static function listObjectRelatedSessions($triggerKey)
	{
		$cacheStores = self::getStores();
		foreach ($cacheStores as $cacheStore)
		{
			if($cacheStore instanceof kCouchbaseCacheWrapper)
			{
				// TODO optimize using elastic search query
				$query = $cacheStore->getNewQuery(kCouchbaseCacheQuery::VIEW_RESPONSE_PROFILE_RELATED_OBJECT_SESSIONS);
				if($query)
				{
					$query->addStartKey('triggerKey', $triggerKey);
					$query->addStartKey('objectType', 'a');
					$query->addStartKey('sessionKey', 'a');
					$query->addEndKey('triggerKey', $triggerKey);
					$query->addEndKey('objectType', 'Z');
					$query->addEndKey('sessionKey', 'Z');
					$query->setLimit(self::MAX_CACHE_KEYS_PER_JOB);

					$offset = 0;
					$array = array();
					$list = $cacheStore->query($query);
					KalturaLog::debug('Found [' . count($list->getObjects()) . '/' . $list->getCount() . '] items');
					while(count($list->getObjects()))
					{
						foreach ($list->getObjects() as $cacheObject)
						{
							/* @var $cacheObject kCouchbaseCacheListItem */
							list($cacheTriggerKey, $cacheObjectType, $cacheSessionKey) = $cacheObject->getKey();
							if(!isset($array[$cacheObjectType]))
							{
								$array[$cacheObjectType] = array();
							}
							if(isset($array[$cacheObjectType][$cacheSessionKey]))
							{
								$array[$cacheObjectType][$cacheSessionKey]++;
							}
							else
							{
								$array[$cacheObjectType][$cacheSessionKey] = 1;
							}
						}
						
						$offset += count($list->getObjects());
						$query->setOffset($offset);
						$list = $cacheStore->query($query);
						KalturaLog::debug('Found [' . count($list->getObjects()) . '/' . $list->getCount() . '] items');
					}
					return $array;
				}
			}
		}
	
		return array();
	}

	protected static function listObjectRelatedTypes($triggerKey)
	{
		$cacheStores = self::getStores();
		foreach ($cacheStores as $cacheStore)
		{
			if($cacheStore instanceof kCouchbaseCacheWrapper)
			{
				// TODO optimize using elastic search query
				$query = $cacheStore->getNewQuery(kCouchbaseCacheQuery::VIEW_RESPONSE_PROFILE_RELATED_OBJECTS_TYPES);
				if($query)
				{
					$query->addStartKey('triggerKey', $triggerKey);
					$query->addStartKey('objectType', 'a');
					$query->addEndKey('triggerKey', $triggerKey);
					$query->addEndKey('objectType', 'Z');
					$query->setLimit(self::MAX_CACHE_KEYS_PER_JOB);

					$offset = 0;
					$array = array();
					$list = $cacheStore->query($query);
					KalturaLog::debug('Found [' . count($list->getObjects()) . '/' . $list->getCount() . '] items');
					while(count($list->getObjects()))
					{
						foreach ($list->getObjects() as $cacheObject)
						{
							/* @var $cacheObject kCouchbaseCacheListItem */
							list($cacheTriggerKey, $cacheObjectType) = $cacheObject->getKey();
							$array[$cacheObjectType] = true;
						}
						
						$offset += count($list->getObjects());
						$query->setOffset($offset);
						$list = $cacheStore->query($query);
						KalturaLog::debug('Found [' . count($list->getObjects()) . '/' . $list->getCount() . '] items');
					}
					return array_keys($array);
				}
			}
		}
	
		return array();
	}

	protected static function listObjectSessionTypes(BaseObject $object)
	{
		$objectKey = self::getObjectKey($object);
		if($object instanceof IBaseObject)
		{
			$cacheStores = self::getStores();
			foreach ($cacheStores as $cacheStore)
			{
				if($cacheStore instanceof kCouchbaseCacheWrapper)
				{
					// TODO optimize using elastic search query
					$query = $cacheStore->getNewQuery(kCouchbaseCacheQuery::VIEW_RESPONSE_PROFILE_OBJECT_SESSIONS);
					if($query)
					{
						$query->addStartKey('objectKey', $objectKey);
						$query->addStartKey('sessionKey', 'a');
						$query->addEndKey('objectKey', $objectKey);
						$query->addEndKey('sessionKey', 'Z');
						$query->setLimit(self::MAX_CACHE_KEYS_PER_JOB);
	
						$list = $cacheStore->query($query);
						KalturaLog::debug('Found [' . count($list->getObjects()) . '/' . $list->getCount() . '] items');
						$array = array();
						foreach ($list->getObjects() as $cacheObject)
						{
							/* @var $cacheObject kCouchbaseCacheListItem */
							list($cacheObjectKey, $cacheSessionKey) = $cacheObject->getKey();
							$array[$cacheSessionKey] = $cacheSessionKey;
						}
						return $array;
					}
				}
			}
		}
	
		return array();
	}

	protected function addRecalculateRelatedObjectsCacheJob(IBaseObject $object)
	{
		KalturaLog::debug('Recalculating object [' . get_class($object) . '] id [' . $object->getPrimaryKey() . '] related objects');
		
		$partnerId = $object->getPartnerId();
		$triggerKey = self::getRelatedObjectKey($object);
		$objectTypes = self::listObjectRelatedSessions($triggerKey);
		foreach($objectTypes as $objectType => $sessionKeys)
		{
			foreach($sessionKeys as $sessionKey => $count)
			{
				list($protocol, $ksType, $userRoles) = explode('_', $sessionKey, 3);
				$userRoles = explode('_', $userRoles);
				if($count > self::MAX_CACHE_KEYS_PER_JOB)
				{
					$startEndDocIds = self::listDocIds($objectType, $sessionKey);
					foreach($startEndDocIds as $startEndDocId)
					{
						list($startDocId, $endDocId) = $startEndDocId;
						kJobsManager::addRecalculateResponseProfileCacheJob($partnerId, $protocol, $ksType, $userRoles, $objectType, null, $startDocId, $endDocId);
					}
				}
				else
				{
					kJobsManager::addRecalculateResponseProfileCacheJob($partnerId, $protocol, $ksType, $userRoles, $objectType);
				}
			}
		}
		return true;
	}
	
	protected function addRecalculateObjectCacheJob(IBaseObject $object)
	{
		KalturaLog::debug('Recalculating object [' . get_class($object) . '] id [' . $object->getPrimaryKey() . '] cache');
		$objectType = get_class($object);
		$objectKey = self::getObjectKey($object);
		$partnerId = $object->getPartnerId();
		
		$sessionTypes = self::listObjectSessionTypes($object);
		foreach($sessionTypes as $sessionKey)
		{
			list($protocol, $ksType, $userRoles) = explode('_', $sessionKey, 3);
			$userRoles = explode('_', $userRoles);
			kJobsManager::addRecalculateResponseProfileCacheJob($partnerId, $protocol, $ksType, $userRoles, $objectType, $object->getPrimaryKey());
		}
		return true;
	}
	
	protected function invalidateCachedRootObjects(IBaseObject $object)
	{
		KalturaLog::debug('Invalidating object [' . get_class($object) . '] id [' . $object->getPrimaryKey() . '] roots');
		$peer = $object->getPeer();
		if($peer instanceof IRelatedObjectPeer)
		{
			$roots = $peer->getRootObjects($object);
			if(is_array($roots))
			{
				foreach($roots as $root)
				{
					$this->invalidateCachedObject($root);
				}
			}
		}
		return true;
	}
	
	protected function invalidateCachedObject(IBaseObject $object)
	{
		self::invalidate(self::getObjectKey($object));
		
		if(PermissionPeer::isValidForPartner(PermissionName::FEATURE_RECALCULATE_RESPONSE_PROFILE_CACHE, $object->getPartnerId()))
		{
			$this->addRecalculateObjectCacheJob($object);
		}
		else
		{
			$this->deleteCachedObjects($object);
		}
		
		return true;
	}
		
	protected function invalidateCachedRelatedObjects(IBaseObject $object)
	{
		self::invalidateRelated($object);
		
		if(PermissionPeer::isValidForPartner(PermissionName::FEATURE_RECALCULATE_RESPONSE_PROFILE_CACHE, $object->getPartnerId()))
		{
			$this->addRecalculateRelatedObjectsCacheJob($object);
		}
		
		return true;
	}
		
	protected function deleteCachedObjects(IBaseObject $object)
	{
		$objectKey = self::getObjectKey($object);
		KalturaLog::debug("Invalidating object [" . get_class($object) . "] id [" . $object->getPrimaryKey() . "] key [$objectKey]");
		
		/* @var $object IBaseObject */
		$cacheStores = self::getStores();
		foreach ($cacheStores as $cacheStore)
		{
			if($cacheStore instanceof kCouchbaseCacheWrapper)
			{
				$query = $cacheStore->getNewQuery(kCouchbaseCacheQuery::VIEW_RESPONSE_PROFILE_OBJECT_SPECIFIC);
				if($query)
				{
					$query->setKey($objectKey);
					$query->setLimit(100);
					
					$deletedKeys = array();
					$list = $cacheStore->query($query);
					KalturaLog::debug('Found [' . count($list->getObjects()) . '/' . $list->getCount() . '] items');
					while($list->getCount())
					{
						$keys = array();
						foreach($list->getObjects() as $cache)
						{
							/* @var $cache kCouchbaseCacheListItem */
							if(!isset($deletedKeys[$cache->getId()]))
							{
								$keys[] = $cache->getId();
								$deletedKeys[$cache->getId()] = true;
							}
						}
						if(!count($keys))
						{
							break;
						}
						$cacheStore->multiDelete($keys);
						$list = $cacheStore->query($query);
						KalturaLog::debug('Found [' . count($list->getObjects()) . '/' . $list->getCount() . '] items');
					}
				}
			}
		}
	}
}