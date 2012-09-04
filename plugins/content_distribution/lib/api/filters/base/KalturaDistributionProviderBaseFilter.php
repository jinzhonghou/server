<?php
/**
 * @package plugins.contentDistribution
 * @subpackage api.filters.base
 * @abstract
 */
abstract class KalturaDistributionProviderBaseFilter extends KalturaFilter
{
	static private $map_between_objects = array
	(
		"typeEqual" => "_eq_type",
		"typeIn" => "_in_type",
	);

	static private $order_by_map = array
	(
	);

	public function getMapBetweenObjects()
	{
		return array_merge(parent::getMapBetweenObjects(), KalturaDistributionProviderBaseFilter::$map_between_objects);
	}

	public function getOrderByMap()
	{
		return array_merge(parent::getOrderByMap(), KalturaDistributionProviderBaseFilter::$order_by_map);
	}

	/**
	 * @var KalturaDistributionProviderType
	 */
	public $typeEqual;

	/**
	 * @dynamicType KalturaDistributionProviderType
	 * @var string
	 */
	public $typeIn;
}
