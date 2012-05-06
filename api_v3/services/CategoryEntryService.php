<?php

/**
 * Add & Manage CategoryEntry - assign entry to category
 *
 * @service categoryEntry
 */
class CategoryEntryService extends KalturaBaseService
{
	public function initService($serviceId, $serviceName, $actionName)
	{
		parent::initService($serviceId, $serviceName, $actionName);
		parent::applyPartnerFilterForClass(new categoryPeer());
		parent::applyPartnerFilterForClass(new entryPeer());	
	}
	
	/**
	 * Add new CategoryUser
	 * 
	 * @action add
	 * @param KalturaCategoryEntry $categoryEntry
	 * @throws KalturaErrors::INVALID_ENTRY_ID
	 * @throws KalturaErrors::CATEGORY_NOT_FOUND
	 * @throws KalturaErrors::CANNOT_ASSIGN_ENTRY_TO_CATEGORY
	 * @throws KalturaErrors::CATEGORY_ENTRY_ALREADY_EXISTS
	 * @return KalturaCategoryEntry
	 */
	function addAction(KalturaCategoryEntry $categoryEntry)
	{
		$categoryEntry->validateForInsert();
		
		$entry = entryPeer::retrieveByPK($categoryEntry->entryId);
		if (!$entry)
			throw new KalturaAPIException(KalturaErrors::INVALID_ENTRY_ID, $categoryEntry->entryId);
			
		$category = categoryPeer::retrieveByPK($categoryEntry->categoryId);
		if (!$category)
			throw new KalturaAPIException(KalturaErrors::CATEGORY_NOT_FOUND, $categoryEntry->categoryId);
			
		//validate user is entiteld to assign entry to this category 
		if (kEntitlementUtils::getEntitlementEnforcement() && $category->getContributionPolicy() != ContributionPolicyType::ALL)
		{
			$categoryKuser = categoryKuserPeer::retrieveByCategoryIdAndActiveKuserId($categoryEntry->categoryId, kCurrentContext::$ks_kuser_id);
			if(!$categoryKuser || $categoryKuser->getPermissionLevel() == CategoryKuserPermissionLevel::MEMBER)
				throw new KalturaAPIException(KalturaErrors::CANNOT_ASSIGN_ENTRY_TO_CATEGORY);
		}
		
		$categoryEntryExists = categoryEntryPeer::retrieveByCategoryIdAndEntryId($categoryEntry->categoryId, $categoryEntry->entryId);
		if($categoryEntryExists)
			throw new KalturaAPIException(KalturaErrors::CATEGORY_ENTRY_ALREADY_EXISTS);
		
		$dbCategoryEntry = new categoryEntry();
		$categoryEntry->toInsertableObject($dbCategoryEntry);
		
		$partnerId = kCurrentContext::$partner_id ? kCurrentContext::$partner_id : kCurrentContext::$ks_partner_id;
		$dbCategoryEntry->setPartnerId($partnerId);
		$dbCategoryEntry->save();
		
		//need to select the entry again - after update
		$entry = entryPeer::retrieveByPK($categoryEntry->entryId);		
		myNotificationMgr::createNotification(kNotificationJobData::NOTIFICATION_TYPE_ENTRY_UPDATE, $entry);
		
		$categoryEntry = new KalturaCategoryEntry();
		$categoryEntry->fromObject($dbCategoryEntry);

		return $categoryEntry;
	}
	
	/**
	 * Delete CategoryUser
	 * 
	 * @action delete
	 * @param string $entryId
	 * @param int $categoryId
	 * @throws KalturaErrors::INVALID_ENTRY_ID
	 * @throws KalturaErrors::CATEGORY_NOT_FOUND
	 * @throws KalturaErrors::CANNOT_REMOVE_ENTRY_FROM_CATEGORY
	 * @throws KalturaErrors::ENTRY_IS_NOT_ASSIGNED_TO_CATEGORY
	 * 
	 */
	function deleteAction($entryId, $categoryId)
	{
		$entry = entryPeer::retrieveByPK($entryId);
		if (!$entry)
			throw new KalturaAPIException(KalturaErrors::INVALID_ENTRY_ID, $entryId);
			
		$category = categoryPeer::retrieveByPK($categoryId);
		if (!$category)
			throw new KalturaAPIException(KalturaErrors::CATEGORY_NOT_FOUND, $categoryId);
		
		//validate user is entiteld to remove entry from category 
		$categoryKuser = categoryKuserPeer::retrieveByCategoryIdAndActiveKuserId($categoryId, kCurrentContext::$ks_kuser_id);
		if(kEntitlementUtils::getEntitlementEnforcement() && (!$categoryKuser || $categoryKuser->getPermissionLevel() == CategoryKuserPermissionLevel::MEMBER))
			throw new KalturaAPIException(KalturaErrors::CANNOT_REMOVE_ENTRY_FROM_CATEGORY);
			
		$dbCategoryEntry = categoryEntryPeer::retrieveByCategoryIdAndEntryId($categoryId, $entryId);
		if(!$dbCategoryEntry)
			throw new KalturaAPIException(KalturaErrors::ENTRY_IS_NOT_ASSIGNED_TO_CATEGORY);
		
		$dbCategoryEntry->delete();
		
		//need to select the entry again - after update
		$entry = entryPeer::retrieveByPK($entryId);		
		myNotificationMgr::createNotification(kNotificationJobData::NOTIFICATION_TYPE_ENTRY_UPDATE, $entry);
	}
	
	/**
	 * List all categoryEntry
	 * 
	 * @action list
	 * @param KalturaCategoryEntryFilter $filter
	 * @param KalturaFilterPager $pager
	 * @throws KalturaErrors::MUST_FILTER_ENTRY_ID_EQUAL
	 * @throws KalturaErrors::MUST_FILTER_ON_ENTRY_OR_CATEGORY
	 * @return KalturaCategoryEntryListResponse
	 */
	function listAction(KalturaCategoryEntryFilter $filter = null, KalturaFilterPager $pager = null)
	{
		if ($filter === null)
			$filter = new KalturaCategoryEntryFilter();
		
		if ($pager == null)
			$pager = new KalturaFilterPager();
		
		if ($filter->entryIdEqual == null && 
			kEntitlementUtils::getEntitlementEnforcement())
			throw new KalturaAPIException(KalturaErrors::MUST_FILTER_ENTRY_ID_EQUAL);
			
		if ($filter->entryIdEqual == null &&
			$filter->categoryFullIdsEqual == null &&
			$filter->categoryFullIdsStartsWith == null &&
			$filter->categoryIdIn == null &&
			$filter->categoryIdEqual == null)
			throw new KalturaAPIException(KalturaErrors::MUST_FILTER_ON_ENTRY_OR_CATEGORY);		
			
		$categoryEntryFilter = new categoryEntryFilter();
		$filter->toObject($categoryEntryFilter);
		 
		$c = KalturaCriteria::create(categoryEntryPeer::OM_CLASS);
		$categoryEntryFilter->attachToCriteria($c);
		$pager->attachToCriteria($c);
		$dbCategoriesEntry = categoryEntryPeer::doSelect($c);
		
		if(kEntitlementUtils::getEntitlementEnforcement() && count($dbCategoriesEntry))
		{
			
			//remove unlisted categories: display in search is set to members only
			$categoriesIds = array();
			foreach ($dbCategoriesEntry as $dbCategoryEntry)
				$categoriesIds[] = $dbCategoryEntry->getCategoryId();
				
			$c = KalturaCriteria::create(categoryPeer::OM_CLASS);
			$c->addSelectColumn(categoryPeer::ID);
			$c->addAnd(categoryPeer::ID, $categoriesIds, Criteria::IN);
			$stmt = categoryPeer::doSelectStmt($c);
			$categoryIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
			
			foreach ($dbCategoriesEntry as $key => $dbCategoryEntry)
			{
				if(!in_array($dbCategoryEntry->getCategoryId(), $categoryIds))
				{
					KalturaLog::debug('Category [' . print_r($dbCategoryEntry->getCategoryId(),true) . '] is not listed to user');
					unset($dbCategoriesEntry[$key]);
				}
			}
		}
		
		$categoryEntrylist = KalturaCategoryEntryArray::fromCategoryEntryArray($dbCategoriesEntry);
		$response = new KalturaCategoryEntryListResponse();
		$response->objects = $categoryEntrylist;
		$response->totalCount = count($categoryEntrylist); // no pager since category entry is limited to ENTRY::MAX_CATEGORIES_PER_ENTRY
		return $response;
	}
	
	/**
	 * Index CategoryEntry by Id
	 * 
	 * @action index
	 * @param string $entryId
	 * @param int $categoryId
	 * @param bool $shouldUpdate
	 * @throws KalturaErrors::ENTRY_IS_NOT_ASSIGNED_TO_CATEGORY
	 * @return int
	 */
	function indexAction($entryId, $categoryId, $shouldUpdate = true)
	{
		$dbCategoryEntry = categoryEntryPeer::retrieveByCategoryIdAndEntryId($categoryId, $entryId);
		if(!$dbCategoryEntry)
			throw new KalturaAPIException(KalturaErrors::ENTRY_IS_NOT_ASSIGNED_TO_CATEGORY);
			
		if (!$shouldUpdate)
		{
			$dbCategoryEntry->setUpdatedAt(time());
			$dbCategoryEntry->save();
			
			return $dbCategoryEntry->getIntId();
		}
				
		$dbCategoryEntry->reSetCategoryFullIds();

		//TODO should skip all categoryentry logic 
		$dbCategoryEntry->save();
		
		return $dbCategoryEntry->getId();
				
	}
}