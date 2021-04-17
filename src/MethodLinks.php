<?php declare( strict_types=1 );

namespace SecurityCheckPlugin;

use ast\Node;
use Phan\Language\Element\FunctionInterface;

/**
 * Value object that represents method links.
 * @todo We might store links inside Taintedness, but the memory usage might skyrocket
 */
class MethodLinks {
	/** @var LinksSet */
	private $links;

	/** @var self[] */
	private $dimLinks = [];

	/** @var self|null */
	private $unknownDimLinks;

	/**
	 * @param LinksSet $links
	 */
	private function __construct( LinksSet $links ) {
		$this->links = $links;
	}

	/**
	 * @return self
	 */
	public static function newEmpty() : self {
		return new self( new LinksSet );
	}

	/**
	 * @param mixed $dim
	 * @return self
	 */
	public function getForDim( $dim ) : self {
		if ( !is_scalar( $dim ) ) {
			return $this->asValueFirstLevel();
		}
		if ( isset( $this->dimLinks[$dim] ) ) {
			$ret = clone $this->dimLinks[$dim];
			$ret->mergeWith( $this->unknownDimLinks ?? self::newEmpty() );
			$ret->links = self::mergeSets( $ret->links, $this->links );
			return $ret;
		}
		$ret = $this->unknownDimLinks ? clone $this->unknownDimLinks : self::newEmpty();
		$ret->links = self::mergeSets( $ret->links, $this->links );
		return $ret;
	}

	/**
	 * @return self
	 */
	public function asValueFirstLevel() : self {
		$ret = new self( $this->links );
		$ret->mergeWith( $this->unknownDimLinks ?? self::newEmpty() );
		foreach ( $this->dimLinks as $links ) {
			$ret->mergeWith( $links );
		}
		return $ret;
	}

	/**
	 * @param mixed $dim
	 * @param MethodLinks $links
	 */
	public function setAtDim( $dim, self $links ) : void {
		if ( is_scalar( $dim ) ) {
			$this->dimLinks[$dim] = $links;
		} else {
			$this->unknownDimLinks = $this->unknownDimLinks ?? self::newEmpty();
			$this->unknownDimLinks->mergeWith( $links );
		}
	}

	/**
	 * Temporary method, should only be used in getRelevantLinksForTaintedness
	 * @return bool
	 */
	public function hasSomethingOutOfKnownDims() : bool {
		return count( $this->links ) > 0 || ( $this->unknownDimLinks && !$this->unknownDimLinks->isEmpty() );
	}

	/**
	 * @return self
	 */
	public function asCollapsed() : self {
		$ret = new self( $this->links );
		foreach ( $this->dimLinks as $links ) {
			$ret->mergeWith( $links->asCollapsed() );
		}
		if ( $this->unknownDimLinks ) {
			$ret->mergeWith( $this->unknownDimLinks->asCollapsed() );
		}
		return $ret;
	}

	/**
	 * Merge this object with $other, recursively and without creating a copy.
	 *
	 * @param self $other
	 */
	public function mergeWith( self $other ) : void {
		$this->links = self::mergeSets( $this->links, $other->links );
		foreach ( $other->dimLinks as $key => $links ) {
			if ( isset( $this->dimLinks[$key] ) ) {
				$this->dimLinks[$key]->mergeWith( $links );
			} else {
				$this->dimLinks[$key] = $links;
			}
		}
		if ( $other->unknownDimLinks && !$this->unknownDimLinks ) {
			$this->unknownDimLinks = $other->unknownDimLinks;
		} elseif ( $other->unknownDimLinks ) {
			$this->unknownDimLinks->mergeWith( $other->unknownDimLinks );
		}
	}

	/**
	 * Merge this object with $other, recursively, creating a copy.
	 *
	 * @param self $other
	 * @return self
	 */
	public function asMergedWith( self $other ) : self {
		$ret = clone $this;
		$ret->mergeWith( $other );
		return $ret;
	}

	/**
	 * @param array $offsets
	 * @phan-param array<Node|mixed> $offsets
	 * @param MethodLinks $links
	 */
	public function setLinksAtOffsetList( array $offsets, self $links ) : void {
		assert( count( $offsets ) >= 1 );
		$base = $this;
		// Just in case keys are not consecutive
		$offsets = array_values( $offsets );
		$lastIdx = count( $offsets ) - 1;
		foreach ( $offsets as $i => $offset ) {
			$isLast = $i === $lastIdx;
			if ( !is_scalar( $offset ) ) {
				if ( !$base->unknownDimLinks ) {
					$base->unknownDimLinks = self::newEmpty();
				}
				if ( $isLast ) {
					$base->unknownDimLinks = $this->getSetLinksAtOffsetInternal( $base, $offset, $links );
					return;
				}
				$base = $base->unknownDimLinks;
				continue;
			}

			if ( $isLast ) {
				// Mission accomplished!
				$base->dimLinks[$offset] = $this->getSetLinksAtOffsetInternal( $base, $offset, $links );
				break;
			}

			if ( !array_key_exists( $offset, $base->dimLinks ) ) {
				// Create the element as safe and move on
				$base->dimLinks[$offset] = self::newEmpty();
			}
			$base = $base->dimLinks[$offset];
		}
	}

	/**
	 * @param self $base
	 * @param Node|mixed $lastOffset
	 * @param self $links
	 * @return self
	 */
	private function getSetLinksAtOffsetInternal( self $base, $lastOffset, self $links ) : self {
		if ( !is_scalar( $lastOffset ) ) {
			return $base->unknownDimLinks
				? $base->unknownDimLinks->asMergedWith( $links )
				: $links;
		}
		return isset( $base->dimLinks[$lastOffset] )
			? $base->dimLinks[$lastOffset]->asMergedWith( $links )
			: $links;
	}

	/**
	 * Make sure to clone member variables, too.
	 */
	public function __clone() {
		foreach ( $this->links as $method ) {
			$this->links[$method] = clone $this->links[$method];
		}
		foreach ( $this->dimLinks as $k => $links ) {
			$this->dimLinks[$k] = clone $links;
		}
		if ( $this->unknownDimLinks ) {
			$this->unknownDimLinks = clone $this->unknownDimLinks;
		}
	}

	/**
	 * Temporary method until proper handlers are created.
	 *
	 * @return LinksSet
	 */
	public function getLinks() : LinksSet {
		$ret = $this->links;
		foreach ( $this->dimLinks as $link ) {
			$ret = self::mergeSets( $ret, $link->getLinks() );
		}
		if ( $this->unknownDimLinks ) {
			$ret = self::mergeSets( $ret, $this->unknownDimLinks->getLinks() );
		}
		return $ret;
	}

	/**
	 * @return bool
	 */
	public function isEmpty() : bool {
		if ( count( $this->links ) ) {
			return false;
		}
		foreach ( $this->dimLinks as $links ) {
			if ( !$links->isEmpty() ) {
				return false;
			}
		}
		if ( $this->unknownDimLinks && !$this->unknownDimLinks->isEmpty() ) {
			return false;
		}
		return true;
	}

	/**
	 * @param FunctionInterface $func
	 * @param int $i
	 */
	public function initializeParamForFunc( FunctionInterface $func, int $i ) : void {
		if ( $this->links->contains( $func ) ) {
			$this->links[$func]->addParam( $i );
		} else {
			$this->links[$func] = SingleMethodLinks::newWithParam( $i );
		}
	}

	/**
	 * @param LinksSet $l1
	 * @param LinksSet $l2
	 * @return LinksSet
	 */
	private static function mergeSets( LinksSet $l1, LinksSet $l2 ) : LinksSet {
		$ret = $l1;
		$remainingL2 = new LinksSet;
		foreach ( $l2 as $method ) {
			if ( $ret->contains( $method ) ) {
				$ret[$method]->mergeWith( $l2[$method] );
			} else {
				$remainingL2->attach( $method, $l2[$method] );
			}
		}
		return $ret->unionWith( $remainingL2 );
	}

	/**
	 * @return string
	 */
	public function __toString() : string {
		$ret = 'OWN: ' . $this->links->__toString() . ';';
		if ( !$this->dimLinks ) {
			return $ret;
		}
		$ret .= ' CHILDREN: ';
		foreach ( $this->dimLinks as $key => $links ) {
			$ret .= "\n\t$key: " . $links->__toString();
		}
		if ( $this->unknownDimLinks ) {
			$ret .= "\n\t(UNKNOWN): " . $this->unknownDimLinks->__toString();
		}
		return $ret;
	}

	/**
	 * @suppress PhanUnreferencedPublicMethod
	 * @return string
	 */
	public function toString() : string {
		return $this->__toString();
	}
}
