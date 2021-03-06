<?php

/**
 * Elasticsearch wrapper for WP_Meta_Query
 */
class ES_WP_Meta_Query extends WP_Meta_Query {

	/**
	 * Some object which extends ES_WP_Query_Wrapper.
	 *
	 * @var ES_WP_Query_Wrapper
	 */
	protected $es_query;

	/**
	 * Turns an array of meta query parameters into ES Query DSL
	 *
	 * @access public
	 *
	 * @param object $es_query Any object which extends ES_WP_Query_Wrapper.
	 * @param string $type Type of meta. Currently, only 'post' is supported.
	 * @return array ES filters
	 */
	public function get_dsl( $es_query, $type ) {
		// Currently only 'post' is supported
		if ( 'post' != $type ) {
			return false;
		}

		$this->es_query = $es_query;

		$filters = $this->get_dsl_clauses();

		return apply_filters_ref_array( 'get_meta_dsl', array( $filters, $this->queries, $type, $this->es_query ) );
	}

	/**
	 * Generate ES Filter clauses to be appended to a main query.
	 *
	 * Called by the public {@see ES_WP_Meta_Query::get_dsl()}, this method
	 * is abstracted out to maintain parity with the other Query classes.
	 *
	 * @access protected
	 *
	 * @return array
	 */
	protected function get_dsl_clauses() {
		/*
		 * $queries are passed by reference to
		 * `ES_WP_Meta_Query::get_dsl_for_query()` for recursion. To keep
		 * $this->queries unaltered, pass a copy.
		 */
		$queries = $this->queries;
		return $this->get_dsl_for_query( $queries );
	}

	/**
	 * Generate ES filters for a single query array.
	 *
	 * If nested subqueries are found, this method recurses the tree to produce
	 * the properly nested DSL.
	 *
	 * @access protected
	 *
	 * @param array $query Query to parse, passed by reference.
	 * @return array Array containing nested ES filter clauses.
	 */
	protected function get_dsl_for_query( &$query ) {
		$filters = array();

		foreach ( $query as $key => &$clause ) {
			if ( 'relation' === $key ) {
				$relation = $query['relation'];
			} elseif ( is_array( $clause ) ) {
				if ( $this->is_first_order_clause( $clause ) ) {
					// This is a first-order clause.
					$filters[] = $this->get_dsl_for_clause( $clause, $query, $key );
				} else {
					// This is a subquery, so we recurse.
					$filters[] = $this->get_dsl_for_query( $clause );
				}
			}
		}

		// Filter to remove empties.
		$filters = array_filter( $filters );
		$this->clauses = array_filter( $this->clauses );

		if ( empty( $relation ) ) {
			$relation = 'and';
		}

		if ( count( $filters ) > 1 ) {
			$filters = array( strtolower( $relation ) => $filters );
		} elseif ( ! empty( $filters ) ) {
			$filters = reset( $filters );
		}

		return $filters;
	}

	/**
	 * Generate ES filter clauses for a first-order query clause.
	 *
	 * "First-order" means that it's an array with a 'key' or 'value'.
	 *
	 * @access public
	 *
	 * @param array  $clause       Query clause, passed by reference.
	 * @param array  $query        Parent query array.
	 * @param string $clause_key   Optional. The array key used to name the
	 *                             clause in the original `$meta_query`
	 *                             parameters. If not provided, a key will be
	 *                             generated automatically.
	 * @return array ES filter clause component.
	 */
	public function get_dsl_for_clause( &$clause, $query, $clause_key = '' ) {
		// Key must be a string, so fallback for clause keys is 'meta-clause'.
		if ( is_int( $clause_key ) || ! $clause_key ) {
			$clause_key = 'meta-clause';
		}

		// Ensure unique clause keys, so none are overwritten.
		$iterator = 1;
		$clause_key_base = $clause_key;
		while ( isset( $this->clauses[ $clause_key ] ) ) {
			$clause_key = $clause_key_base . '-' . $iterator;
			$iterator++;
		}

		// Split out 'exists' and 'not exists' queries. These may also be
		// queries missing a value or with an empty array as the value.
		if ( isset( $clause['compare'] ) && ! empty( $clause['value'] ) ) {
			if ( 'EXISTS' == strtoupper( $clause['compare'] ) ) {
				$clause['compare'] = is_array( $clause['value'] ) ? 'IN' : '=';
			} elseif ( 'NOT EXISTS' == strtoupper( $clause['compare'] ) ) {
				unset( $clause['value'] );
			}
		}

		if ( ( isset( $clause['value'] ) && is_array( $clause['value'] ) && empty( $clause['value'] ) ) || ( ! array_key_exists( 'value', $clause ) && ! empty( $clause['key'] ) ) ) {
			$this->clauses[ $clause_key ] =& $clause;
			if ( isset( $clause['compare'] ) && 'NOT EXISTS' == strtoupper( $clause['compare'] ) ) {
				return $this->es_query->dsl_missing( $this->es_query->meta_map( trim( $clause['key'] ) ) );
			} else {
				return $this->es_query->dsl_exists( $this->es_query->meta_map( trim( $clause['key'] ) ) );
			}
		}

		$clause['key'] = isset( $clause['key'] ) ? trim( $clause['key'] ) : '*';

		if ( array_key_exists( 'value', $clause ) && is_null( $clause['value'] ) ) {
			$clause['value'] = '';
		}

		$clause['value'] = isset( $clause['value'] ) ? $clause['value'] : null;

		if ( isset( $clause['compare'] ) ) {
			$clause['compare'] = strtoupper( $clause['compare'] );
		} else {
			$clause['compare'] = is_array( $clause['value'] ) ? 'IN' : '=';
		}

		if ( in_array( $clause['compare'], array( 'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN' ) ) ) {
			if ( ! is_array( $clause['value'] ) ) {
				$clause['value'] = preg_split( '/[,\s]+/', $clause['value'] );
			}

			if ( empty( $clause['value'] ) ) {
				// This compare type requires an array of values. If we don't
				// have one, we bail on this query.
				return array();
			}
		} else {
			$clause['value'] = trim( $clause['value'] );
		}

		// Store the clause in our flat array.
		$this->clauses[ $clause_key ] =& $clause;

		if ( '*' == $clause['key'] && ! in_array( $clause['compare'], array( '=', '!=', 'LIKE', 'NOT LIKE' ) ) ) {
			return apply_filters( 'es_meta_query_keyless_query', array(), $clause['value'], $clause['compare'], $this, $this->es_query );
		}

		$clause['type'] = $this->get_cast_for_type( isset( $clause['type'] ) ? $clause['type'] : '' );

		// Allow adapters to normalize meta values (like `strtolower` if mapping to `raw_lc`)
		$clause['value'] = apply_filters( 'es_meta_query_meta_value', $clause['value'], $clause['key'], $clause['compare'], $clause['type'] );

		switch ( $clause['compare'] ) {
			case '>' :
			case '>=' :
			case '<' :
			case '<=' :
				switch ( $clause['compare'] ) {
					case '>' :   $operator = 'gt';   break;
					case '>=' :  $operator = 'gte';  break;
					case '<' :   $operator = 'lt';   break;
					case '<=' :  $operator = 'lte';  break;
				}
				$filter = $this->es_query->dsl_range( $this->es_query->meta_map( $clause['key'], $clause['type'] ), array( $operator => $clause['value'] ) );
				break;

			case 'LIKE' :
			case 'NOT LIKE' :
				if ( '*' == $clause['key'] ) {
					$filter = array( 'query' => $this->es_query->dsl_multi_match( $this->es_query->meta_map( $clause['key'], 'analyzed' ), $clause['value'] ) );
				} else {
					$filter = array( 'query' => $this->es_query->dsl_match( $this->es_query->meta_map( $clause['key'], 'analyzed' ), $clause['value'] ) );
				}
				break;

			case 'BETWEEN' :
			case 'NOT BETWEEN' :
				// These may produce unexpected results depending on how your data is indexed.
				$clause['value'] = array_slice( $clause['value'], 0, 2 );
				if ( 'DATETIME' == $clause['type'] && $date1 = strtotime( $clause['value'][0] ) && $date2 = strtotime( $clause['value'][1] ) ) {
					$clause['value'] = array( $date1, $date2 );
					sort( $clause['value'] );
					$filter = $this->es_query->dsl_range(
						$this->es_query->meta_map( $clause['key'], $clause['type'] ),
						ES_WP_Date_Query::build_date_range( $clause['value'][0], '>=', $clause['value'][1], '<=' )
					);
				} else {
					natcasesort( $clause['value'] );
					$filter = $this->es_query->dsl_range(
						$this->es_query->meta_map( $clause['key'], $clause['type'] ),
						array( 'gte' => $clause['value'][0], 'lte' => $clause['value'][1] )
					);
				}
				break;

			case 'REGEXP' :
			case 'NOT REGEXP' :
			case 'RLIKE' :
				_doing_it_wrong( 'ES_WP_Query', __( 'ES_WP_Query does not support regular expression meta queries.', 'es-wp-query' ), '0.1' );
				// Empty out $clause, since this will be disregarded.
				$clause = array();
				return array();
				break;

			default :
				if ( '*' == $clause['key'] ) {
					$filter = array( 'query' => $this->es_query->dsl_multi_match( $this->es_query->meta_map( $clause['key'], $clause['type'] ), $clause['value'] ) );
				} else {
					$filter = $this->es_query->dsl_terms( $this->es_query->meta_map( $clause['key'], $clause['type'] ), $clause['value'] );
				}
				break;

		}

		if ( ! empty( $filter ) ) {
			// To maintain parity with WP_Query, if we're doing a negation
			// query, we still only query posts where the meta key exists.
			if ( in_array( $clause['compare'], array( 'NOT IN', '!=', 'NOT BETWEEN', 'NOT LIKE' ) ) ) {
				return array(
					'and' => array(
						$this->es_query->dsl_exists( $this->es_query->meta_map( $clause['key'] ) ),
						array( 'not' => $filter ),
					),
				);
			} else {
				return $filter;
			}
		}

	}

	/**
	 * Get the ES mapping suffix for the given type.
	 *
	 * @param  string $type Meta_Query type. See Meta_Query docs.
	 * @return string
	 */
	public function get_cast_for_type( $type = '' ) {
		$type = preg_replace( '/^([A-Z]+).*$/', '$1', strtoupper( $type ) );
		switch ( $type ) {
			case 'NUMERIC'  : return 'long';
			case 'SIGNED'   : return 'long';
			case 'UNSIGNED' : return 'long';
			case 'BINARY'   : return 'boolean';
			case 'DECIMAL'  : return 'double';
			case 'DATE'     : return 'date';
			case 'DATETIME' : return 'datetime';
			case 'TIME'     : return 'time';
		}
		return '';
	}
}
