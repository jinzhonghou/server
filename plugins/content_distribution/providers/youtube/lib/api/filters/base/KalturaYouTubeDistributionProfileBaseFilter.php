<?php
/**
 * @package plugins.youTubeDistribution
 * @subpackage api.filters.base
 * @abstract
 */
abstract class KalturaYouTubeDistributionProfileBaseFilter extends KalturaConfigurableDistributionProfileFilter
{
	static private $map_between_objects = array
	(
	);

	static private $order_by_map = array
	(
	);

	public function getMapBetweenObjects()
	{
		return array_merge(parent::getMapBetweenObjects(), KalturaYouTubeDistributionProfileBaseFilter::$map_between_objects);
	}

	public function getOrderByMap()
	{
		return array_merge(parent::getOrderByMap(), KalturaYouTubeDistributionProfileBaseFilter::$order_by_map);
	}
}
