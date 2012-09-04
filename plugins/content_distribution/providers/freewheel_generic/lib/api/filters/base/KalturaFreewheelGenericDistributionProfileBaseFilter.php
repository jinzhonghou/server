<?php
/**
 * @package plugins.freewheelGenericDistribution
 * @subpackage api.filters.base
 * @abstract
 */
abstract class KalturaFreewheelGenericDistributionProfileBaseFilter extends KalturaConfigurableDistributionProfileFilter
{
	static private $map_between_objects = array
	(
	);

	static private $order_by_map = array
	(
	);

	public function getMapBetweenObjects()
	{
		return array_merge(parent::getMapBetweenObjects(), KalturaFreewheelGenericDistributionProfileBaseFilter::$map_between_objects);
	}

	public function getOrderByMap()
	{
		return array_merge(parent::getOrderByMap(), KalturaFreewheelGenericDistributionProfileBaseFilter::$order_by_map);
	}
}
