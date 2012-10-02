<?php
/**
 * @package plugins.caption
 * @subpackage model.filters
 */
class EntryCaptionAssetSearchFilter extends AdvancedSearchFilterItem
{
	/**
	 * @var string
	 */
	protected  $contentLike;

	/**
	 * @var string
	 */
	protected $contentMultiLikeOr;

	/**
	 * @var string
	 */
	protected $contentMultiLikeAnd;
	
	
	/**
	 * @return the $contentLike
	 */
	public function getContentLike() {
		return $this->contentLike;
	}

	/**
	 * @return the $contentMultiLikeOr
	 */
	public function getContentMultiLikeOr() {
		return $this->contentMultiLikeOr;
	}

	/**
	 * @return the $contentMultiLikeAnd
	 */
	public function getContentMultiLikeAnd() {
		return $this->contentMultiLikeAnd;
	}

	/**
	 * @param string $contentLike
	 */
	public function setContentLike($contentLike) {
		$this->contentLike = $contentLike;
	}

	/**
	 * @param string $contentMultiLikeOr
	 */
	public function setContentMultiLikeOr($contentMultiLikeOr) {
		$this->contentMultiLikeOr = $contentMultiLikeOr;
	}

	/**
	 * @param string $contentMultiLikeAnd
	 */
	public function setContentMultiLikeAnd($contentMultiLikeAnd) {
		$this->contentMultiLikeAnd = $contentMultiLikeAnd;
	}

	private function addCondition($conditionStr, IKalturaIndexQuery $query)
	{		
		if(!is_null($conditionStr))
		{
			$condition = "ca_prefix<<$conditionStr<<ca_sufix";
			KalturaLog::debug("condition [" . print_r($condition, true) . "]");
			$key = '@' . CaptionSearchPlugin::getSearchFieldName(CaptionSearchPlugin::SEARCH_FIELD_DATA);
			$query->addMatch("($key $condition)");			
		}
	}
	
	/* (non-PHPdoc)
	 * @see AdvancedSearchFilterItem::applyCondition()
	 */
	public function applyCondition(IKalturaIndexQuery $query)
	{
		$this->addCondition($this->getContentLike(), $query);
		$this->addCondition($this->getContentMultiLikeAnd(), $query);
		$this->addCondition($this->getContentMultiLikeOr(), $query);
	}
	
	public function addToXml(SimpleXMLElement &$xmlElement)
	{
		parent::addToXml($xmlElement);
		
		$xmlElement->addAttribute('contentLike', $this->contentLike);
		$xmlElement->addAttribute('contentMultiLikeAnd', $this->contentMultiLikeAnd);
		$xmlElement->addAttribute('contentMultiLikeOr', $this->contentMultiLikeOr);
	}
	
	public function fillObjectFromXml(SimpleXMLElement $xmlElement)
	{
		parent::fillObjectFromXml($xmlElement);
		
		$attr = $xmlElement->attributes();
		if(isset($attr['contentLike']) && strlen($attr['contentLike']))
			$this->contentLike = $attr['contentLike'];
		if(isset($attr['contentMultiLikeAnd']) && strlen($attr['contentMultiLikeAnd']))
			$this->contentMultiLikeAnd = $attr['contentMultiLikeAnd'];
		if(isset($attr['contentMultiLikeOr']) && strlen($attr['contentMultiLikeOr']))
			$this->contentMultiLikeOr = $attr['contentMultiLikeOr'];
	}
}
