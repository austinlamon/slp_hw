<?php
/**
 * CakePHP(tm) : Rapid Development Framework (http://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link          http://cakephp.org CakePHP(tm) Project
 * @since         3.0.0
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Test\TestCase\Database;

use Cake\Core\Configure;
use Cake\Database\Expression\IdentifierExpression;
use Cake\Database\Query;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Tests Query class
 *
 */
class QueryTest extends TestCase {

	public $fixtures = ['core.article', 'core.author', 'core.comment'];

	const ARTICLE_COUNT = 3;
	const AUTHOR_COUNT = 4;
	const COMMENT_COUNT = 6;

	public function setUp() {
		parent::setUp();
		$this->connection = ConnectionManager::get('test');
		$this->autoQuote = $this->connection->driver()->autoQuoting();
	}

	public function tearDown() {
		parent::tearDown();
		$this->connection->driver()->autoQuoting($this->autoQuote);
		unset($this->connection);
	}

/**
 * Queries need a default type to prevent fatal errors
 * when an uninitialized query has its sql() method called.
 *
 * @return void
 */
	public function testDefaultType() {
		$query = new Query($this->connection);
		$this->assertEquals('', $query->sql());
		$this->assertEquals('select', $query->type());
	}

/**
 * Tests that it is possible to obtain expression results from a query
 *
 * @return void
 */
	public function testSelectFieldsOnly() {
		$this->connection->driver()->autoQuoting(false);
		$query = new Query($this->connection);
		$result = $query->select('1 + 1')->execute();
		$this->assertInstanceOf('Cake\Database\StatementInterface', $result);
		$this->assertEquals([2], $result->fetch());

		//This new field should be appended
		$result = $query->select(array('1 + 3'))->execute();
		$this->assertInstanceOf('Cake\Database\StatementInterface', $result);
		$this->assertEquals([2, 4], $result->fetch());

		//This should now overwrite all previous fields
		$result = $query->select(array('1 + 2', '1 + 5'), true)->execute();
		$this->assertEquals([3, 6], $result->fetch());
	}

/**
 * Tests that it is possible to pass a closure as fields in select()
 *
 * @return void
 */
	public function testSelectClosure() {
		$this->connection->driver()->autoQuoting(false);
		$query = new Query($this->connection);
		$result = $query->select(function($q) use ($query) {
			$this->assertSame($query, $q);
			return ['1 + 2', '1 + 5'];
		})->execute();
		$this->assertEquals([3, 6], $result->fetch());
	}

/**
 * Tests it is possible to select fields from tables with no conditions
 *
 * @return void
 */
	public function testSelectFieldsFromTable() {
		$query = new Query($this->connection);
		$result = $query->select(array('body', 'author_id'))->from('articles')->execute();
		$this->assertEquals(array('body' => 'First Article Body', 'author_id' => 1), $result->fetch('assoc'));
		$this->assertEquals(array('body' => 'Second Article Body', 'author_id' => 3), $result->fetch('assoc'));

		//Append more tables to next execution
		$result = $query->select('name')->from(array('authors'))->order(['name' => 'desc', 'articles.id' => 'asc'])->execute();
		$this->assertEquals(array('body' => 'First Article Body', 'author_id' => 1, 'name' => 'nate'), $result->fetch('assoc'));
		$this->assertEquals(array('body' => 'Second Article Body', 'author_id' => 3, 'name' => 'nate'), $result->fetch('assoc'));
		$this->assertEquals(array('body' => 'Third Article Body', 'author_id' => 1, 'name' => 'nate'), $result->fetch('assoc'));

		// Overwrite tables and only fetch from authors
		$result = $query->select('name', true)->from('authors', true)->order(['name' => 'desc'], true)->execute();
		$this->assertEquals(array('nate'), $result->fetch());
		$this->assertEquals(array('mariano'), $result->fetch());
		$this->assertCount(4, $result);
	}

/**
 * Tests it is possible to select aliased fields
 *
 * @return void
 */
	public function testSelectAliasedFieldsFromTable() {
		$query = new Query($this->connection);
		$result = $query->select(['text' => 'body', 'author_id'])->from('articles')->execute();
		$this->assertEquals(array('text' => 'First Article Body', 'author_id' => 1), $result->fetch('assoc'));
		$this->assertEquals(array('text' => 'Second Article Body', 'author_id' => 3), $result->fetch('assoc'));

		$query = new Query($this->connection);
		$result = $query->select(['text' => 'body', 'author' => 'author_id'])->from('articles')->execute();
		$this->assertEquals(array('text' => 'First Article Body', 'author' => 1), $result->fetch('assoc'));
		$this->assertEquals(array('text' => 'Second Article Body', 'author' => 3), $result->fetch('assoc'));

		$query = new Query($this->connection);
		$query->select(['text' => 'body'])->select(['author_id', 'foo' => 'body']);
		$result = $query->from('articles')->execute();
		$this->assertEquals(array('foo' => 'First Article Body', 'text' => 'First Article Body', 'author_id' => 1), $result->fetch('assoc'));
		$this->assertEquals(array('foo' => 'Second Article Body', 'text' => 'Second Article Body', 'author_id' => 3), $result->fetch('assoc'));

		$query = new Query($this->connection);
		$exp = $query->newExpr('1 + 1');
		$comp = $query->newExpr(['author_id +' => 2]);
		$result = $query->select(['text' => 'body', 'two' => $exp, 'three' => $comp])
			->from('articles')->execute();
		$this->assertEquals(array('text' => 'First Article Body', 'two' => 2, 'three' => 3), $result->fetch('assoc'));
		$this->assertEquals(array('text' => 'Second Article Body', 'two' => 2, 'three' => 5), $result->fetch('assoc'));
	}

/**
 * Tests that tables can also be aliased and referenced in the select clause using such alias
 *
 * @return void
 */
	public function testSelectAliasedTables() {
		$query = new Query($this->connection);
		$result = $query->select(['text' => 'a.body', 'a.author_id'])
			->from(['a' => 'articles'])->execute();

		$this->assertEquals(['text' => 'First Article Body', 'author_id' => 1], $result->fetch('assoc'));
		$this->assertEquals(['text' => 'Second Article Body', 'author_id' => 3], $result->fetch('assoc'));

		$result = $query->select(['name' => 'b.name'])->from(['b' => 'authors'])
			->order(['text' => 'desc', 'name' => 'desc'])
			->execute();
		$this->assertEquals(
			['text' => 'Third Article Body', 'author_id' => 1, 'name' => 'nate'],
			$result->fetch('assoc')
		);
		$this->assertEquals(
			['text' => 'Third Article Body', 'author_id' => 1, 'name' => 'mariano'],
			$result->fetch('assoc')
		);
	}

/**
 * Tests it is possible to add joins to a select query
 *
 * @return void
 */
	public function testSelectWithJoins() {
		$query = new Query($this->connection);
		$result = $query
			->select(['title', 'name'])
			->from('articles')
			->join(['table' => 'authors', 'alias' => 'a', 'conditions' => 'author_id = a.id'])
			->order(['title' => 'asc'])
			->execute();

		$this->assertCount(3, $result);
		$this->assertEquals(array('title' => 'First Article', 'name' => 'mariano'), $result->fetch('assoc'));
		$this->assertEquals(array('title' => 'Second Article', 'name' => 'larry'), $result->fetch('assoc'));

		$result = $query->join('authors', [], true)->execute();
		$this->assertCount(12, $result, 'Cross join results in 12 records');

		$result = $query->join([
			['table' => 'authors', 'type' => 'INNER', 'conditions' => 'author_id = authors.id']
		], [], true)->execute();
		$this->assertCount(3, $result);
		$this->assertEquals(array('title' => 'First Article', 'name' => 'mariano'), $result->fetch('assoc'));
		$this->assertEquals(array('title' => 'Second Article', 'name' => 'larry'), $result->fetch('assoc'));
	}

/**
 * Tests it is possible to add joins to a select query using array or expression as conditions
 *
 * @return void
 */
	public function testSelectWithJoinsConditions() {
		$query = new Query($this->connection);
		$result = $query
			->select(['title', 'name'])
			->from('articles')
			->join(['table' => 'authors', 'alias' => 'a', 'conditions' => ['author_id = a.id']])
			->order(['title' => 'asc'])
			->execute();
		$this->assertEquals(array('title' => 'First Article', 'name' => 'mariano'), $result->fetch('assoc'));
		$this->assertEquals(array('title' => 'Second Article', 'name' => 'larry'), $result->fetch('assoc'));

		$query = new Query($this->connection);
		$conditions = $query->newExpr('author_id = a.id');
		$result = $query
			->select(['title', 'name'])
			->from('articles')
			->join(['table' => 'authors', 'alias' => 'a', 'conditions' => $conditions])
			->order(['title' => 'asc'])
			->execute();
		$this->assertEquals(array('title' => 'First Article', 'name' => 'mariano'), $result->fetch('assoc'));
		$this->assertEquals(array('title' => 'Second Article', 'name' => 'larry'), $result->fetch('assoc'));

		$query = new Query($this->connection);
		$time = new \DateTime('2007-03-18 10:50:00');
		$types = ['created' => 'datetime'];
		$result = $query
			->select(['title', 'comment' => 'c.comment'])
			->from('articles')
			->join(['table' => 'comments', 'alias' => 'c', 'conditions' => ['created <=' => $time]], $types)
			->execute();
		$this->assertEquals(['title' => 'First Article', 'comment' => 'First Comment for First Article'], $result->fetch('assoc'));
	}

/**
 * Tests that joins can be aliased using array keys
 *
 * @return void
 */
	public function testSelectAliasedJoins() {
		$query = new Query($this->connection);
		$result = $query
			->select(['title', 'name'])
			->from('articles')
			->join(['a' => 'authors'])
			->order(['name' => 'desc', 'articles.id' => 'asc'])
			->execute();
		$this->assertEquals(array('title' => 'First Article', 'name' => 'nate'), $result->fetch('assoc'));
		$this->assertEquals(array('title' => 'Second Article', 'name' => 'nate'), $result->fetch('assoc'));

		$query = new Query($this->connection);
		$conditions = $query->newExpr('author_id = a.id');
		$result = $query
			->select(['title', 'name'])
			->from('articles')
			->join(['a' => ['table' => 'authors', 'conditions' => $conditions]])
			->order(['title' => 'asc'])
			->execute();
		$this->assertEquals(array('title' => 'First Article', 'name' => 'mariano'), $result->fetch('assoc'));
		$this->assertEquals(array('title' => 'Second Article', 'name' => 'larry'), $result->fetch('assoc'));

		$query = new Query($this->connection);
		$time = new \DateTime('2007-03-18 10:45:23');
		$types = ['created' => 'datetime'];
		$result = $query
			->select(['title', 'name' => 'c.comment'])
			->from('articles')
			->join(['c' => ['table' => 'comments', 'conditions' => ['created' => $time]]], $types)
			->execute();
		$this->assertEquals(array('title' => 'First Article', 'name' => 'First Comment for First Article'), $result->fetch('assoc'));
	}

/**
 * Tests the leftJoin method
 *
 * @return void
 */
	public function testSelectLeftJoin() {
		$query = new Query($this->connection);
		$time = new \DateTime('2007-03-18 10:45:23');
		$types = ['created' => 'datetime'];
		$result = $query
			->select(['title', 'name' => 'c.comment'])
			->from('articles')
			->leftJoin(['c' => 'comments'], ['created <' => $time], $types)
			->execute();
		$this->assertEquals(array('title' => 'First Article', 'name' => null), $result->fetch('assoc'));

		$query = new Query($this->connection);
		$result = $query
			->select(['title', 'name' => 'c.comment'])
			->from('articles')
			->leftJoin(['c' => 'comments'], ['created >' => $time], $types)
			->execute();
		$this->assertEquals(
			['title' => 'First Article', 'name' => 'Second Comment for First Article'],
			$result->fetch('assoc')
		);
	}

/**
 * Tests the innerJoin method
 *
 * @return void
 */
	public function testSelectInnerJoin() {
		$query = new Query($this->connection);
		$time = new \DateTime('2007-03-18 10:45:23');
		$types = ['created' => 'datetime'];
		$result = $query
			->select(['title', 'name' => 'c.comment'])
			->from('articles')
			->innerJoin(['c' => 'comments'], ['created <' => $time], $types)
			->execute();
		$this->assertCount(0, $result->fetchAll());

		$query = new Query($this->connection);
		$result = $query
			->select(['title', 'name' => 'c.comment'])
			->from('articles')
			->leftJoin(['c' => 'comments'], ['created >' => $time], $types)
			->execute();
		$this->assertEquals(
			['title' => 'First Article', 'name' => 'Second Comment for First Article'],
			$result->fetch('assoc')
		);
	}

/**
 * Tests the rightJoin method
 *
 * @return void
 */
	public function testSelectRightJoin() {
		$this->skipIf(
			$this->connection->driver() instanceof \Cake\Database\Driver\Sqlite,
			'SQLite does not support RIGHT joins'
		);
		$query = new Query($this->connection);
		$time = new \DateTime('2007-03-18 10:45:23');
		$types = ['created' => 'datetime'];
		$result = $query
			->select(['title', 'name' => 'c.comment'])
			->from('articles')
			->rightJoin(['c' => 'comments'], ['created <' => $time], $types)
			->execute();
		$this->assertCount(6, $result);
		$this->assertEquals(
			['title' => null, 'name' => 'First Comment for First Article'],
			$result->fetch('assoc')
		);
	}

/**
 * Tests that it is possible to pass a callable as conditions for a join
 *
 * @return void
 */
	public function testSelectJoinWithCallback() {
		$query = new Query($this->connection);
		$types = ['created' => 'datetime'];
		$result = $query
			->select(['title', 'name' => 'c.comment'])
			->from('articles')
			->innerJoin(['c' => 'comments'], function($exp, $q) use ($query, $types) {
				$this->assertSame($q, $query);
				$exp->add(['created <' => new \DateTime('2007-03-18 10:45:23')], $types);
				return $exp;
			})
			->execute();
		$this->assertCount(0, $result->fetchAll());

		$query = new Query($this->connection);
		$types = ['created' => 'datetime'];
		$result = $query
			->select(['title', 'name' => 'comments.comment'])
			->from('articles')
			->innerJoin('comments', function($exp, $q) use ($query, $types) {
				$this->assertSame($q, $query);
				$exp->add(['created >' => new \DateTime('2007-03-18 10:45:23')], $types);
				return $exp;
			})
			->execute();
		$this->assertEquals(
			['title' => 'First Article', 'name' => 'Second Comment for First Article'],
			$result->fetch('assoc')
		);
	}

/**
 * Tests it is possible to filter a query by using simple AND joined conditions
 *
 * @return void
 */
	public function testSelectSimpleWhere() {
		$query = new Query($this->connection);
		$result = $query
			->select(['title'])
			->from('articles')
			->where(['id' => 1, 'title' => 'First Article'])
			->execute();
		$this->assertCount(1, $result);

		$query = new Query($this->connection);
		$result = $query
			->select(['title'])
			->from('articles')
			->where(['id' => 100], ['id' => 'integer'])
			->execute();
		$this->assertCount(0, $result);
	}

/**
 * Tests using where conditions with operators and scalar values works
 *
 * @return void
 */
	public function testSelectWhereOperators() {
		$query = new Query($this->connection);
		$result = $query
			->select(['title'])
			->from('articles')
			->where(['id >' => 1])
			->execute();
		$this->assertCount(2, $result);
		$this->assertEquals(array('title' => 'Second Article'), $result->fetch('assoc'));

		$query = new Query($this->connection);
		$result = $query
			->select(['title'])
			->from('articles')
			->where(['id <' => 2])
			->execute();
		$this->assertCount(1, $result);
		$this->assertEquals(array('title' => 'First Article'), $result->fetch('assoc'));

		$query = new Query($this->connection);
		$result = $query
			->select(['title'])
			->from('articles')
			->where(['id <=' => 2])
			->execute();
		$this->assertCount(2, $result);

		$query = new Query($this->connection);
		$result = $query
			->select(['title'])
			->from('articles')
			->where(['id >=' => 1])
			->execute();
		$this->assertCount(3, $result);

		$query = new Query($this->connection);
		$result = $query
			->select(['title'])
			->from('articles')
			->where(['id <=' => 1])
			->execute();
		$this->assertCount(1, $result);

		$query = new Query($this->connection);
		$result = $query
			->select(['title'])
			->from('articles')
			->where(['id !=' => 2])
			->execute();
		$this->assertCount(2, $result);
		$this->assertEquals(array('title' => 'First Article'), $result->fetch('assoc'));

		$query = new Query($this->connection);
		$result = $query
			->select(['title'])
			->from('articles')
			->where(['title LIKE' => 'First Article'])
			->execute();
		$this->assertCount(1, $result);
		$this->assertEquals(array('title' => 'First Article'), $result->fetch('assoc'));

		$query = new Query($this->connection);
		$result = $query
			->select(['title'])
			->from('articles')
			->where(['title like' => '%Article%'])
			->execute();
		$this->assertCount(3, $result);

		$query = new Query($this->connection);
		$result = $query
			->select(['title'])
			->from('articles')
			->where(['title not like' => '%Article%'])
			->execute();
		$this->assertCount(0, $result);
	}

/**
 * Tests selecting with conditions and specifying types for those
 *
 * @return void
 */
	public function testSelectWhereTypes() {
		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('comments')
			->where(['created' => new \DateTime('2007-03-18 10:45:23')], ['created' => 'datetime'])
			->execute();
		$this->assertCount(1, $result);
		$this->assertEquals(['id' => 1], $result->fetch('assoc'));

		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('comments')
			->where(['created >' => new \DateTime('2007-03-18 10:46:00')], ['created' => 'datetime'])
			->execute();
		$this->assertCount(5, $result);
		$this->assertEquals(['id' => 2], $result->fetch('assoc'));
		$this->assertEquals(['id' => 3], $result->fetch('assoc'));

		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('comments')
			->where(
				[
					'created >' => new \DateTime('2007-03-18 10:40:00'),
					'created <' => new \DateTime('2007-03-18 10:46:00')
				],
				['created' => 'datetime']
			)
			->execute();
		$this->assertCount(1, $result);
		$this->assertEquals(array('id' => 1), $result->fetch('assoc'));

		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('comments')
			->where(
				[
					'id' => '3something-crazy',
					'created <' => new \DateTime('2013-01-01 12:00')
				],
				['created' => 'datetime', 'id' => 'integer']
			)
			->execute();
		$this->assertCount(1, $result);
		$this->assertEquals(['id' => 3], $result->fetch('assoc'));

		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('comments')
			->where(
				[
					'id' => '1something-crazy',
					'created <' => new \DateTime('2013-01-01 12:00')
				],
				['created' => 'datetime', 'id' => 'float']
			)
			->execute();
		$this->assertCount(1, $result);
		$this->assertEquals(['id' => 1], $result->fetch('assoc'));
	}

/**
 * Tests that passing an array type to any where condition will replace
 * the passed array accordingly as a proper IN condition
 *
 * @return void
 */
	public function testSelectWhereArrayType() {
		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('comments')
			->where(['id' => ['1', '3']], ['id' => 'integer[]'])
			->execute();
		$this->assertCount(2, $result);
		$this->assertEquals(['id' => 1], $result->fetch('assoc'));
		$this->assertEquals(['id' => 3], $result->fetch('assoc'));
	}

/**
 * Tests that passing an empty array type to any where condition will not
 * result in an error, but in an empty result set
 *
 * @return void
 */
	public function testSelectWhereArrayTypeEmpty() {
		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('comments')
			->where(['id' => []], ['id' => 'integer[]'])
			->execute();
		$this->assertCount(0, $result);
	}

/**
 * Tests that Query::orWhere() can be used to concatenate conditions with OR
 *
 * @return void
 */
	public function testSelectOrWhere() {
		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('comments')
			->where(['created' => new \DateTime('2007-03-18 10:45:23')], ['created' => 'datetime'])
			->orWhere(['created' => new \DateTime('2007-03-18 10:47:23')], ['created' => 'datetime'])
			->execute();
		$this->assertCount(2, $result);
		$this->assertEquals(['id' => 1], $result->fetch('assoc'));
		$this->assertEquals(['id' => 2], $result->fetch('assoc'));
	}

/**
 * Tests that Query::andWhere() can be used to concatenate conditions with AND
 *
 * @return void
 */
	public function testSelectAndWhere() {
		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('comments')
			->where(['created' => new \DateTime('2007-03-18 10:45:23')], ['created' => 'datetime'])
			->andWhere(['id' => 1])
			->execute();
		$this->assertCount(1, $result);
		$this->assertEquals(['id' => 1], $result->fetch('assoc'));

		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('comments')
			->where(['created' => new \DateTime('2007-03-18 10:50:55')], ['created' => 'datetime'])
			->andWhere(['id' => 2])
			->execute();
		$this->assertCount(0, $result);
	}

/**
 * Tests that combining Query::andWhere() and Query::orWhere() produces
 * correct conditions nesting
 *
 * @return void
 */
	public function testSelectExpressionNesting() {
		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('comments')
			->where(['created' => new \DateTime('2007-03-18 10:45:23')], ['created' => 'datetime'])
			->orWhere(['id' => 2])
			->andWhere(['created >=' => new \DateTime('2007-03-18 10:40:00')], ['created' => 'datetime'])
			->execute();
		$this->assertCount(2, $result);
		$this->assertEquals(['id' => 1], $result->fetch('assoc'));
		$this->assertEquals(['id' => 2], $result->fetch('assoc'));

		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('comments')
			->where(['created' => new \DateTime('2007-03-18 10:45:23')], ['created' => 'datetime'])
			->orWhere(['id' => 2])
			->andWhere(['created >=' => new \DateTime('2007-03-18 10:40:00')], ['created' => 'datetime'])
			->orWhere(['created' => new \DateTime('2007-03-18 10:49:23')], ['created' => 'datetime'])
			->execute();
		$this->assertCount(3, $result);
		$this->assertEquals(['id' => 1], $result->fetch('assoc'));
		$this->assertEquals(['id' => 2], $result->fetch('assoc'));
		$this->assertEquals(['id' => 3], $result->fetch('assoc'));
	}

/**
 * Tests that Query::orWhere() can be used without calling where() before
 *
 * @return void
 */
	public function testSelectOrWhereNoPreviousCondition() {
		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('comments')
			->orWhere(['created' => new \DateTime('2007-03-18 10:45:23')], ['created' => 'datetime'])
			->orWhere(['created' => new \DateTime('2007-03-18 10:47:23')], ['created' => 'datetime'])
			->execute();
		$this->assertCount(2, $result);
		$this->assertEquals(['id' => 1], $result->fetch('assoc'));
		$this->assertEquals(['id' => 2], $result->fetch('assoc'));
	}

/**
 * Tests that Query::andWhere() can be used without calling where() before
 *
 * @return void
 */
	public function testSelectAndWhereNoPreviousCondition() {
		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('comments')
			->andWhere(['created' => new \DateTime('2007-03-18 10:45:23')], ['created' => 'datetime'])
			->andWhere(['id' => 1])
			->execute();
		$this->assertCount(1, $result);
		$this->assertEquals(['id' => 1], $result->fetch('assoc'));
	}

/**
 * Tests that it is possible to pass a closure to where() to build a set of
 * conditions and return the expression to be used
 *
 * @return void
 */
	public function testSelectWhereUsingClosure() {
		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('comments')
			->where(function($exp) {
				return $exp->eq('id', 1);
			})
			->execute();
		$this->assertCount(1, $result);
		$this->assertEquals(['id' => 1], $result->fetch('assoc'));

		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('comments')
			->where(function($exp) {
				return $exp
					->eq('id', 1)
					->eq('created', new \DateTime('2007-03-18 10:45:23'), 'datetime');
			})
			->execute();
		$this->assertCount(1, $result);
		$this->assertEquals(['id' => 1], $result->fetch('assoc'));

		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('comments')
			->where(function($exp) {
				return $exp
					->eq('id', 1)
					->eq('created', new \DateTime('2021-12-30 15:00'), 'datetime');
			})
			->execute();
		$this->assertCount(0, $result);
	}

/**
 * Tests generating tuples in the values side containing closure expressions
 *
 * @return void
 */
	public function testTupleWithClosureExpression() {
		$query = new Query($this->connection);
		$query->select(['id'])
			->from('comments')
			->where([
				'OR' => [
					'id' => 1,
					function($exp) {
						return $exp->eq('id', 2);
					}
				]
			]);

		$result = $query->sql();
		$this->assertQuotedQuery(
			'SELECT <id> FROM <comments> WHERE \(<id> = :c0 OR <id> = :c1\)',
			$result,
			true
		);
	}

/**
 * Tests that it is possible to pass a closure to andWhere() to build a set of
 * conditions and return the expression to be used
 *
 * @return void
 */
	public function testSelectAndWhereUsingClosure() {
		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('comments')
			->where(['id' => '1'])
			->andWhere(function($exp) {
				return $exp->eq('created', new \DateTime('2007-03-18 10:45:23'), 'datetime');
			})
			->execute();
		$this->assertCount(1, $result);
		$this->assertEquals(['id' => 1], $result->fetch('assoc'));

		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('comments')
			->where(['id' => '1'])
			->andWhere(function($exp) {
				return $exp->eq('created', new \DateTime('2022-12-21 12:00'), 'datetime');
			})
			->execute();
		$this->assertCount(0, $result);
	}

/**
 * Tests that it is possible to pass a closure to orWhere() to build a set of
 * conditions and return the expression to be used
 *
 * @return void
 */
	public function testSelectOrWhereUsingClosure() {
		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('comments')
			->where(['id' => '1'])
			->orWhere(function($exp) {
				return $exp->eq('created', new \DateTime('2007-03-18 10:47:23'), 'datetime');
			})
			->execute();
		$this->assertCount(2, $result);
		$this->assertEquals(['id' => 1], $result->fetch('assoc'));
		$this->assertEquals(['id' => 2], $result->fetch('assoc'));

		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('comments')
			->where(['id' => '1'])
			->orWhere(function($exp) {
				return $exp
					->eq('created', new \DateTime('2012-12-22 12:00'), 'datetime')
					->eq('id', 3);
			})
			->execute();
		$this->assertCount(1, $result);
		$this->assertEquals(['id' => 1], $result->fetch('assoc'));
	}

/**
 * Tests that expression objects can be used as the field in a comparison
 * and the values will be bound correctly to the query
 *
 * @return void
 */
	public function testSelectWhereUsingExpressionInField() {
		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('comments')
			->where(function($exp) {
				$field = clone $exp;
				$field->add('SELECT min(id) FROM comments');
				return $exp
					->eq($field, 100, 'integer');
			})
			->execute();
		$this->assertCount(0, $result);
	}

/**
 * Tests using where conditions with operator methods
 *
 * @return void
 */
	public function testSelectWhereOperatorMethods() {
		$query = new Query($this->connection);
		$result = $query
			->select(['title'])
			->from('articles')
			->where(function($exp) {
				return $exp->gt('id', 1);
			})
			->execute();
		$this->assertCount(2, $result);
		$this->assertEquals(array('title' => 'Second Article'), $result->fetch('assoc'));

		$query = new Query($this->connection);
		$result = $query->select(['title'])
			->from('articles')
			->where(function($exp) {
				return $exp->lt('id', 2);
			})
			->execute();
		$this->assertCount(1, $result);
		$this->assertEquals(array('title' => 'First Article'), $result->fetch('assoc'));

		$query = new Query($this->connection);
		$result = $query->select(['title'])
			->from('articles')
			->where(function($exp) {
				return $exp->lte('id', 2);
			})
			->execute();
		$this->assertCount(2, $result);

		$query = new Query($this->connection);
		$result = $query
			->select(['title'])
			->from('articles')
			->where(function($exp) {
				return $exp->gte('id', 1);
			})
			->execute();
		$this->assertCount(3, $result);

		$query = new Query($this->connection);
		$result = $query
			->select(['title'])
			->from('articles')
			->where(function($exp) {
				return $exp->lte('id', 1);
			})
			->execute();
		$this->assertCount(1, $result);

		$query = new Query($this->connection);
		$result = $query
			->select(['title'])
			->from('articles')
			->where(function($exp) {
				return $exp->notEq('id', 2);
			})
			->execute();
		$this->assertCount(2, $result);
		$this->assertEquals(array('title' => 'First Article'), $result->fetch('assoc'));

		$query = new Query($this->connection);
		$result = $query
			->select(['title'])
			->from('articles')
			->where(function($exp) {
				return $exp->like('title', 'First Article');
			})
			->execute();
		$this->assertCount(1, $result);
		$this->assertEquals(array('title' => 'First Article'), $result->fetch('assoc'));

		$query = new Query($this->connection);
		$result = $query
			->select(['title'])
			->from('articles')
			->where(function($exp) {
				return $exp->like('title', '%Article%');
			})
			->execute();
		$this->assertCount(3, $result);

		$query = new Query($this->connection);
		$result = $query
			->select(['title'])
			->from('articles')
			->where(function($exp) {
				return $exp->notLike('title', '%Article%');
			})
			->execute();
		$this->assertCount(0, $result);

		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('comments')
			->where(function($exp) {
				return $exp->isNull('published');
			})
			->execute();
		$this->assertCount(0, $result);

		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('comments')
			->where(function($exp) {
				return $exp->isNotNull('published');
			})
			->execute();
		$this->assertCount(6, $result);

		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('comments')
			->where(function($exp) {
				return $exp->in('published', ['Y', 'N']);
			})
			->execute();
		$this->assertCount(6, $result);

		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('comments')
			->where(function($exp) {
				return $exp->in(
					'created',
					[new \DateTime('2007-03-18 10:45:23'), new \DateTime('2007-03-18 10:47:23')],
					'datetime'
				);
			})
			->execute();
		$this->assertCount(2, $result);
		$this->assertEquals(['id' => 1], $result->fetch('assoc'));
		$this->assertEquals(['id' => 2], $result->fetch('assoc'));

		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('comments')
			->where(function($exp) {
				return $exp->notIn(
					'created',
					[new \DateTime('2007-03-18 10:45:23'), new \DateTime('2007-03-18 10:47:23')],
					'datetime'
				);
			})
			->execute();
		$this->assertCount(4, $result);
		$this->assertEquals(['id' => 3], $result->fetch('assoc'));
	}

/**
 * Tests that calling "in" and "notIn" will cast the passed values to an array
 *
 * @return void
 */
	public function testInValueCast() {
		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('comments')
			->where(function($exp) {
				return $exp->in('created', '2007-03-18 10:45:23', 'datetime');
			})
			->execute();
		$this->assertCount(1, $result);
		$this->assertEquals(['id' => 1], $result->fetch('assoc'));

		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('comments')
			->where(function($exp) {
				return $exp->notIn('created', '2007-03-18 10:45:23', 'datetime');
			})
			->execute();
		$this->assertCount(5, $result);
		$this->assertEquals(['id' => 2], $result->fetch('assoc'));
		$this->assertEquals(['id' => 3], $result->fetch('assoc'));
		$this->assertEquals(['id' => 4], $result->fetch('assoc'));
		$this->assertEquals(['id' => 5], $result->fetch('assoc'));

		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('comments')
			->where(function($exp, $q) {
				return $exp->in(
					'created',
					$q->newExpr("'2007-03-18 10:45:23'"),
					'datetime'
				);
			})
			->execute();
		$this->assertCount(1, $result);
		$this->assertEquals(['id' => 1], $result->fetch('assoc'));

		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('comments')
			->where(function($exp, $q) {
				return $exp->notIn(
					'created',
					$q->newExpr("'2007-03-18 10:45:23'"),
					'datetime'
				);
			})
			->execute();
		$this->assertCount(5, $result);
	}

/**
 * Tests that calling "in" and "notIn" will cast the passed values to an array
 *
 * @return void
 */
	public function testInValueCast2() {
		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('comments')
			->where(['created IN' => '2007-03-18 10:45:23'])
			->execute();
		$this->assertCount(1, $result);
		$this->assertEquals(['id' => 1], $result->fetch('assoc'));

		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('comments')
			->where(['created NOT IN' => '2007-03-18 10:45:23'])
			->execute();
		$this->assertCount(5, $result);
	}

/**
 * Tests nesting query expressions both using arrays and closures
 *
 * @return void
 */
	public function testSelectExpressionComposition() {
		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('comments')
			->where(function($exp) {
				$and = $exp->and_(['id' => 2, 'id >' => 1]);
				return $exp->add($and);
			})
			->execute();
		$this->assertCount(1, $result);
		$this->assertEquals(['id' => 2], $result->fetch('assoc'));

		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('comments')
			->where(function($exp) {
				$and = $exp->and_(['id' => 2, 'id <' => 2]);
				return $exp->add($and);
			})
			->execute();
		$this->assertCount(0, $result);

		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('comments')
			->where(function($exp) {
				$and = $exp->and_(function($and) {
					return $and->eq('id', 1)->gt('id', 0);
				});
				return $exp->add($and);
			})
			->execute();
		$this->assertCount(1, $result);
		$this->assertEquals(['id' => 1], $result->fetch('assoc'));

		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('comments')
			->where(function($exp) {
				$or = $exp->or_(['id' => 1]);
				$and = $exp->and_(['id >' => 2, 'id <' => 4]);
				return $or->add($and);
			})
			->execute();
		$this->assertCount(2, $result);
		$this->assertEquals(['id' => 1], $result->fetch('assoc'));
		$this->assertEquals(['id' => 3], $result->fetch('assoc'));

		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('comments')
			->where(function($exp) {
				$or = $exp->or_(function($or) {
					return $or->eq('id', 1)->eq('id', 2);
				});
				return $or;
			})
			->execute();
		$this->assertCount(2, $result);
		$this->assertEquals(['id' => 1], $result->fetch('assoc'));
		$this->assertEquals(['id' => 2], $result->fetch('assoc'));
	}

/**
 * Tests that conditions can be nested with an unary operator using the array notation
 * and the not() method
 *
 * @return void
 */
	public function testSelectWhereNot() {
		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('comments')
			->where(function($exp) {
				return $exp->not(
					$exp->and_(['id' => 2, 'created' => new \DateTime('2007-03-18 10:47:23')], ['created' => 'datetime'])
				);
			})
			->execute();
		$this->assertCount(5, $result);
		$this->assertEquals(['id' => 1], $result->fetch('assoc'));
		$this->assertEquals(['id' => 3], $result->fetch('assoc'));

		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('comments')
			->where(function($exp) {
				return $exp->not(
					$exp->and_(['id' => 2, 'created' => new \DateTime('2012-12-21 12:00')], ['created' => 'datetime'])
				);
			})
			->execute();
		$this->assertCount(6, $result);

		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('articles')
			->where([
				'not' => ['or' => ['id' => 1, 'id >' => 2], 'id' => 3]
			])
			->execute();
		$this->assertCount(2, $result);
		$this->assertEquals(['id' => 1], $result->fetch('assoc'));
		$this->assertEquals(['id' => 2], $result->fetch('assoc'));
	}

/**
 * Tests order() method both with simple fields and expressions
 *
 * @return void
 */
	public function testSelectOrderBy() {
		$query = new Query($this->connection);
		$result = $query
			->select(['id'])
			->from('articles')
			->order(['id' => 'desc'])
			->execute();
		$this->assertEquals(['id' => 3], $result->fetch('assoc'));
		$this->assertEquals(['id' => 2], $result->fetch('assoc'));
		$this->assertEquals(['id' => 1], $result->fetch('assoc'));

		$result = $query->order(['id' => 'asc'])->execute();
		$this->assertEquals(['id' => 1], $result->fetch('assoc'));
		$this->assertEquals(['id' => 2], $result->fetch('assoc'));
		$this->assertEquals(['id' => 3], $result->fetch('assoc'));

		$result = $query->order(['title' => 'asc'])->execute();
		$this->assertEquals(['id' => 1], $result->fetch('assoc'));
		$this->assertEquals(['id' => 2], $result->fetch('assoc'));
		$this->assertEquals(['id' => 3], $result->fetch('assoc'));

		$result = $query->order(['title' => 'asc'], true)->execute();
		$this->assertEquals(['id' => 1], $result->fetch('assoc'));
		$this->assertEquals(['id' => 2], $result->fetch('assoc'));
		$this->assertEquals(['id' => 3], $result->fetch('assoc'));

		$result = $query->order(['title' => 'asc', 'published' => 'asc'], true)
			->execute();
		$this->assertEquals(['id' => 1], $result->fetch('assoc'));
		$this->assertEquals(['id' => 2], $result->fetch('assoc'));
		$this->assertEquals(['id' => 3], $result->fetch('assoc'));

		$expression = $query->newExpr(['(id + :offset) % 2']);
		$result = $query
			->order([$expression, 'id' => 'desc'], true)
			->bind(':offset', 1, null)
			->execute();
		$this->assertEquals(['id' => 3], $result->fetch('assoc'));
		$this->assertEquals(['id' => 1], $result->fetch('assoc'));
		$this->assertEquals(['id' => 2], $result->fetch('assoc'));

		$result = $query
			->order($expression, true)
			->order(['id' => 'asc'])
			->bind(':offset', 1, null)
			->execute();
		$this->assertEquals(['id' => 1], $result->fetch('assoc'));
		$this->assertEquals(['id' => 3], $result->fetch('assoc'));
		$this->assertEquals(['id' => 2], $result->fetch('assoc'));
	}

/**
 * Tests that group by fields can be passed similar to select fields
 * and that it sends the correct query to the database
 *
 * @return void
 */
	public function testSelectGroup() {
		$query = new Query($this->connection);
		$result = $query
			->select(['total' => 'count(author_id)', 'author_id'])
			->from('articles')
			->join(['table' => 'authors', 'alias' => 'a', 'conditions' => 'author_id = a.id'])
			->group('author_id')
			->execute();
		$expected = [['total' => 2, 'author_id' => 1], ['total' => '1', 'author_id' => 3]];
		$this->assertEquals($expected, $result->fetchAll('assoc'));

		$result = $query->select(['total' => 'count(title)', 'name'], true)
			->group(['name'], true)
			->order(['total' => 'asc'])
			->execute();
		$expected = [['total' => 1, 'name' => 'larry'], ['total' => 2, 'name' => 'mariano']];
		$this->assertEquals($expected, $result->fetchAll('assoc'));

		$result = $query->select(['articles.id'])
			->group(['articles.id'])
			->execute();
		$this->assertCount(3, $result);
	}

/**
 * Tests that it is possible to select distinct rows
 *
 * @return void
 */
	public function testSelectDistinct() {
		$query = new Query($this->connection);
		$result = $query
			->select(['author_id'])
			->from(['a' => 'articles'])
			->execute();
		$this->assertCount(3, $result);

		$result = $query->distinct()->execute();
		$this->assertCount(2, $result);

		$result = $query->select(['id'])->distinct(false)->execute();
		$this->assertCount(3, $result);
	}

/**
 * Tests that it is possible to select distinct rows, even filtering by one column
 * this is testing that there is a specific implementation for DISTINCT ON
 *
 * @return void
 */
	public function testSelectDistinctON() {
		$this->skipIf(
			$this->connection->driver() instanceof \Cake\Database\Driver\Sqlserver,
			'Not implemented yet in SqlServer'
		);
		$query = new Query($this->connection);
		$result = $query
			->select(['id', 'author_id'])
			->distinct(['author_id'])
			->from(['a' => 'articles'])
			->execute();
		$this->assertCount(2, $result);
	}

/**
 * Test use of modifiers in the query
 *
 * Testing the generated SQL since the modifiers are usually different per driver
 *
 * @return void
 */
	public function testSelectModifiers() {
		$query = new Query($this->connection);
		$result = $query
			->select(['city', 'state', 'country'])
			->from(['addresses'])
			->modifier('DISTINCTROW');
		$this->assertQuotedQuery(
			'SELECT DISTINCTROW <city>, <state>, <country> FROM <addresses>',
			$result->sql(),
			true
		);

		$query = new Query($this->connection);
		$result = $query
			->select(['city', 'state', 'country'])
			->from(['addresses'])
			->modifier(['DISTINCTROW', 'SQL_NO_CACHE']);
		$this->assertQuotedQuery(
			'SELECT DISTINCTROW SQL_NO_CACHE <city>, <state>, <country> FROM <addresses>',
			$result->sql(),
			true
		);

		$query = new Query($this->connection);
		$result = $query
			->select(['city', 'state', 'country'])
			->from(['addresses'])
			->modifier('DISTINCTROW')
			->modifier('SQL_NO_CACHE');
		$this->assertQuotedQuery(
			'SELECT DISTINCTROW SQL_NO_CACHE <city>, <state>, <country> FROM <addresses>',
			$result->sql(),
			true
		);

		$query = new Query($this->connection);
		$result = $query
			->select(['city', 'state', 'country'])
			->from(['addresses'])
			->modifier(['TOP 10']);
		$this->assertQuotedQuery(
			'SELECT TOP 10 <city>, <state>, <country> FROM <addresses>',
			$result->sql(),
			true
		);
	}

/**
 * Tests that having() behaves pretty much the same as the where() method
 *
 * @return void
 */
	public function testSelectHaving() {
		$query = new Query($this->connection);
		$result = $query
			->select(['total' => 'count(author_id)', 'author_id'])
			->from('articles')
			->join(['table' => 'authors', 'alias' => 'a', 'conditions' => 'author_id = a.id'])
			->group('author_id')
			->having(['count(author_id) <' => 2], ['count(author_id)' => 'integer'])
			->execute();
		$expected = [['total' => 1, 'author_id' => 3]];
		$this->assertEquals($expected, $result->fetchAll('assoc'));

		$result = $query->having(['count(author_id)' => 2], ['count(author_id)' => 'integer'], true)
			->execute();
		$expected = [['total' => 2, 'author_id' => 1]];
		$this->assertEquals($expected, $result->fetchAll('assoc'));

		$result = $query->having(function($e) {
			return $e->add('count(author_id) = 1 + 1');
		}, [], true)
			->execute();
		$expected = [['total' => 2, 'author_id' => 1]];
		$this->assertEquals($expected, $result->fetchAll('assoc'));
	}

/**
 * Tests that Query::orHaving() can be used to concatenate conditions with OR
 * in the having clause
 *
 * @return void
 */
	public function testSelectOrHaving() {
		$query = new Query($this->connection);
		$result = $query
			->select(['total' => 'count(author_id)', 'author_id'])
			->from('articles')
			->join(['table' => 'authors', 'alias' => 'a', 'conditions' => 'author_id = a.id'])
			->group('author_id')
			->having(['count(author_id) >' => 2], ['count(author_id)' => 'integer'])
			->orHaving(['count(author_id) <' => 2], ['count(author_id)' => 'integer'])
			->execute();
		$expected = [['total' => 1, 'author_id' => 3]];
		$this->assertEquals($expected, $result->fetchAll('assoc'));

		$query = new Query($this->connection);
		$result = $query
			->select(['total' => 'count(author_id)', 'author_id'])
			->from('articles')
			->join(['table' => 'authors', 'alias' => 'a', 'conditions' => 'author_id = a.id'])
			->group('author_id')
			->having(['count(author_id) >' => 2], ['count(author_id)' => 'integer'])
			->orHaving(['count(author_id) <=' => 2], ['count(author_id)' => 'integer'])
			->execute();
		$expected = [['total' => 2, 'author_id' => 1], ['total' => 1, 'author_id' => 3]];
		$this->assertEquals($expected, $result->fetchAll('assoc'));

		$query = new Query($this->connection);
		$result = $query
			->select(['total' => 'count(author_id)', 'author_id'])
			->from('articles')
			->join(['table' => 'authors', 'alias' => 'a', 'conditions' => 'author_id = a.id'])
			->group('author_id')
			->having(['count(author_id) >' => 2], ['count(author_id)' => 'integer'])
			->orHaving(function($e) {
				return $e->add('count(author_id) = 1 + 1');
			})
			->execute();
		$expected = [['total' => 2, 'author_id' => 1]];
		$this->assertEquals($expected, $result->fetchAll('assoc'));
	}

/**
 * Tests that Query::andHaving() can be used to concatenate conditions with AND
 * in the having clause
 *
 * @return void
 */
	public function testSelectAndHaving() {
		$query = new Query($this->connection);
		$result = $query
			->select(['total' => 'count(author_id)', 'author_id'])
			->from('articles')
			->join(['table' => 'authors', 'alias' => 'a', 'conditions' => 'author_id = a.id'])
			->group('author_id')
			->having(['count(author_id) >' => 2], ['count(author_id)' => 'integer'])
			->andHaving(['count(author_id) <' => 2], ['count(author_id)' => 'integer'])
			->execute();
		$this->assertCount(0, $result);

		$query = new Query($this->connection);
		$result = $query
			->select(['total' => 'count(author_id)', 'author_id'])
			->from('articles')
			->join(['table' => 'authors', 'alias' => 'a', 'conditions' => 'author_id = a.id'])
			->group('author_id')
			->having(['count(author_id)' => 2], ['count(author_id)' => 'integer'])
			->andHaving(['count(author_id) >' => 1], ['count(author_id)' => 'integer'])
			->execute();
		$expected = [['total' => 2, 'author_id' => 1]];
		$this->assertEquals($expected, $result->fetchAll('assoc'));

		$query = new Query($this->connection);
		$result = $query
			->select(['total' => 'count(author_id)', 'author_id'])
			->from('articles')
			->join(['table' => 'authors', 'alias' => 'a', 'conditions' => 'author_id = a.id'])
			->group('author_id')
			->andHaving(function($e) {
				return $e->add('count(author_id) = 2 - 1');
			})
			->execute();
		$expected = [['total' => 1, 'author_id' => 3]];
		$this->assertEquals($expected, $result->fetchAll('assoc'));
	}

/**
 * Tests selecting rows using a limit clause
 *
 * @return void
 */
	public function testSelectLimit() {
		$query = new Query($this->connection);
		$result = $query->select('id')->from('articles')->limit(1)->execute();
		$this->assertCount(1, $result);

		$query = new Query($this->connection);
		$result = $query->select('id')->from('articles')->limit(2)->execute();
		$this->assertCount(2, $result);
	}

/**
 * Tests selecting rows combining a limit and offset clause
 *
 * @return void
 */
	public function testSelectOffset() {
		$query = new Query($this->connection);
		$result = $query->select('id')->from('comments')
			->limit(1)
			->offset(0)
			->order(['id' => 'ASC'])
			->execute();
		$this->assertCount(1, $result);
		$this->assertEquals(['id' => 1], $result->fetch('assoc'));

		$query = new Query($this->connection);
		$result = $query->select('id')->from('comments')
			->limit(1)
			->offset(1)
			->execute();
		$this->assertCount(1, $result);
		$this->assertEquals(['id' => 2], $result->fetch('assoc'));

		$query = new Query($this->connection);
		$result = $query->select('id')->from('comments')
			->limit(1)
			->offset(2)
			->execute();
		$this->assertCount(1, $result);
		$this->assertEquals(['id' => 3], $result->fetch('assoc'));

		$query = new Query($this->connection);
		$result = $query->select('id')->from('articles')
			->order(['id' => 'DESC'])
			->limit(1)
			->offset(0)
			->execute();
		$this->assertCount(1, $result);
		$this->assertEquals(['id' => 3], $result->fetch('assoc'));

		$result = $query->limit(2)->offset(1)->execute();
		$this->assertCount(2, $result);
		$this->assertEquals(['id' => 2], $result->fetch('assoc'));
		$this->assertEquals(['id' => 1], $result->fetch('assoc'));
	}

/**
 * Test selecting rows using the page() method.
 *
 * @return void
 */
	public function testSelectPage() {
		$query = new Query($this->connection);
		$result = $query->select('id')->from('comments')
			->limit(1)
			->page(1)
			->execute();

		$this->assertCount(1, $result);
		$this->assertEquals(['id' => 1], $result->fetch('assoc'));

		$query = new Query($this->connection);
		$result = $query->select('id')->from('comments')
			->limit(1)
			->page(2)
			->execute();
		$this->assertCount(1, $result);
		$this->assertEquals(['id' => 2], $result->fetch('assoc'));

		$query = new Query($this->connection);
		$query->select('id')->from('comments')->page(1);
		$this->assertEquals(25, $query->clause('limit'));
		$this->assertEquals(0, $query->clause('offset'));

		$query->select('id')->from('comments')->page(2);
		$this->assertEquals(25, $query->clause('limit'));
		$this->assertEquals(25, $query->clause('offset'));
	}

/**
 * Tests that Query objects can be included inside the select clause
 * and be used as a normal field, including binding any passed parameter
 *
 * @return void
 */
	public function testSubqueryInSelect() {
		$query = new Query($this->connection);
		$subquery = (new Query($this->connection))
			->select('name')
			->from(['b' => 'authors'])
			->where(['b.id = a.id']);
		$result = $query
			->select(['id', 'name' => $subquery])
			->from(['a' => 'comments'])->execute();

		$expected = [
			['id' => 1, 'name' => 'mariano'],
			['id' => 2, 'name' => 'nate'],
			['id' => 3, 'name' => 'larry'],
			['id' => 4, 'name' => 'garrett'],
			['id' => 5, 'name' => null],
			['id' => 6, 'name' => null],
		];
		$this->assertEquals($expected, $result->fetchAll('assoc'));

		$query = new Query($this->connection);
		$subquery = (new Query($this->connection))
			->select('name')
			->from(['b' => 'authors'])
			->where(['name' => 'mariano'], ['name' => 'string']);
		$result = $query
			->select(['id', 'name' => $subquery])
			->from(['a' => 'articles'])->execute();

		$expected = [
			['id' => 1, 'name' => 'mariano'],
			['id' => 2, 'name' => 'mariano'],
			['id' => 3, 'name' => 'mariano'],
		];
		$this->assertEquals($expected, $result->fetchAll('assoc'));
	}

/**
 * Tests that Query objects can be included inside the from clause
 * and be used as a normal table, including binding any passed parameter
 *
 * @return void
 */
	public function testSuqueryInFrom() {
		$query = new Query($this->connection);
		$subquery = (new Query($this->connection))
			->select(['id', 'comment'])
			->from('comments')
			->where(['created >' => new \DateTime('2007-03-18 10:45:23')], ['created' => 'datetime']);
		$result = $query
			->select(['say' => 'comment'])
			->from(['b' => $subquery])
			->where(['id !=' => 3])
			->execute();

		$expected = [
			['say' => 'Second Comment for First Article'],
			['say' => 'Fourth Comment for First Article'],
			['say' => 'First Comment for Second Article'],
			['say' => 'Second Comment for Second Article'],
		];
		$this->assertEquals($expected, $result->fetchAll('assoc'));
	}

/**
 * Tests that Query objects can be included inside the where clause
 * and be used as a normal condition, including binding any passed parameter
 *
 * @return void
 */
	public function testSubqueryInWhere() {
		$query = new Query($this->connection);
		$subquery = (new Query($this->connection))
			->select(['id'])
			->from('authors')
			->where(['id' => 1]);
		$result = $query
			->select(['name'])
			->from(['authors'])
			->where(['id !=' => $subquery])
			->execute();

		$expected = [
			['name' => 'nate'],
			['name' => 'larry'],
			['name' => 'garrett'],
		];
		$this->assertEquals($expected, $result->fetchAll('assoc'));

		$query = new Query($this->connection);
		$subquery = (new Query($this->connection))
			->select(['id'])
			->from('comments')
			->where(['created >' => new \DateTime('2007-03-18 10:45:23')], ['created' => 'datetime']);
		$result = $query
			->select(['name'])
			->from(['authors'])
			->where(['id not in' => $subquery])
			->execute();

		$expected = [
			['name' => 'mariano'],
		];
		$this->assertEquals($expected, $result->fetchAll('assoc'));
	}

/**
 * Tests that it is possible to use a subquery in a join clause
 *
 * @return void
 */
	public function testSubqueyInJoin() {
		$subquery = (new Query($this->connection))->select('*')->from('authors');

		$query = new Query($this->connection);
		$result = $query
			->select(['title', 'name'])
			->from('articles')
			->join(['b' => $subquery])
			->execute();
		$this->assertCount(self::ARTICLE_COUNT * self::AUTHOR_COUNT, $result, 'Cross join causes multiplication');

		$subquery->where(['id' => 1]);
		$result = $query->execute();
		$this->assertCount(3, $result);

		$query->join(['b' => ['table' => $subquery, 'conditions' => ['b.id = articles.id']]], [], true);
		$result = $query->execute();
		$this->assertCount(1, $result);
	}

/**
 * Tests that it is possible to one or multiple UNION statements in a query
 *
 * @return void
 */
	public function testUnion() {
		$union = (new Query($this->connection))->select(['id', 'title'])->from(['a' => 'articles']);
		$query = new Query($this->connection);
		$result = $query->select(['id', 'comment'])
			->from(['c' => 'comments'])
			->union($union)
			->execute();
		$this->assertCount(self::COMMENT_COUNT + self::ARTICLE_COUNT, $result);
		$rows = $result->fetchAll();

		$union->select(['foo' => 'id', 'bar' => 'title']);
		$union = (new Query($this->connection))
			->select(['id', 'name', 'other' => 'id', 'nameish' => 'name'])
			->from(['b' => 'authors'])
			->where(['id ' => 1])
			->order(['id' => 'desc']);

		$query->select(['foo' => 'id', 'bar' => 'comment'])->union($union);
		$result = $query->execute();
		$this->assertCount(self::COMMENT_COUNT + self::AUTHOR_COUNT, $result);
		$this->assertNotEquals($rows, $result->fetchAll());

		$union = (new Query($this->connection))
			->select(['id', 'title'])
			->from(['c' => 'articles']);
		$query->select(['id', 'comment'], true)->union($union, true);
		$result = $query->execute();
		$this->assertCount(self::COMMENT_COUNT + self::ARTICLE_COUNT, $result);
		$this->assertEquals($rows, $result->fetchAll());
	}

/**
 * Tests that UNION ALL can be built by setting the second param of union() to true
 *
 * @return void
 */
	public function testUnionAll() {
		$union = (new Query($this->connection))->select(['id', 'title'])->from(['a' => 'articles']);
		$query = new Query($this->connection);
		$result = $query->select(['id', 'comment'])
			->from(['c' => 'comments'])
			->union($union)
			->execute();
		$this->assertCount(self::ARTICLE_COUNT + self::COMMENT_COUNT, $result);
		$rows = $result->fetchAll();

		$union->select(['foo' => 'id', 'bar' => 'title']);
		$union = (new Query($this->connection))
			->select(['id', 'name', 'other' => 'id', 'nameish' => 'name'])
			->from(['b' => 'authors'])
			->where(['id ' => 1])
			->order(['id' => 'desc']);

		$query->select(['foo' => 'id', 'bar' => 'comment'])->unionAll($union);
		$result = $query->execute();
		$this->assertCount(1 + self::COMMENT_COUNT + self::ARTICLE_COUNT, $result);
		$this->assertNotEquals($rows, $result->fetchAll());
	}

/**
 * Tests stacking decorators for results and resetting the list of decorators
 *
 * @return void
 */
	public function testDecorateResults() {
		$query = new Query($this->connection);
		$result = $query
			->select(['id', 'title'])
			->from('articles')
			->order(['id' => 'ASC'])
			->decorateResults(function($row) {
				$row['modified_id'] = $row['id'] + 1;
				return $row;
			})
			->execute();

		while ($row = $result->fetch('assoc')) {
			$this->assertEquals($row['id'] + 1, $row['modified_id']);
		}

		$result = $query->decorateResults(function($row) {
			$row['modified_id']--;
			return $row;
		})->execute();

		while ($row = $result->fetch('assoc')) {
			$this->assertEquals($row['id'], $row['modified_id']);
		}

		$result = $query
			->decorateResults(function($row) {
				$row['foo'] = 'bar';
				return $row;
			}, true)
			->execute();

		while ($row = $result->fetch('assoc')) {
			$this->assertEquals('bar', $row['foo']);
			$this->assertArrayNotHasKey('modified_id', $row);
		}

		$results = $query->decorateResults(null, true)->execute();
		while ($row = $result->fetch('assoc')) {
			$this->assertArrayNotHasKey('foo', $row);
			$this->assertArrayNotHasKey('modified_id', $row);
		}
	}

/**
 * Test a basic delete using from()
 *
 * @return void
 */
	public function testDeleteWithFrom() {
		$query = new Query($this->connection);

		$query->delete()
			->from('authors')
			->where('1 = 1');

		$result = $query->sql();
		$this->assertQuotedQuery('DELETE FROM <authors>', $result, true);

		$result = $query->execute();
		$this->assertInstanceOf('Cake\Database\StatementInterface', $result);
		$this->assertCount(self::AUTHOR_COUNT, $result);
	}

/**
 * Test delete with from and alias.
 *
 * @return void
 */
	public function testDeleteWithAliasedFrom() {
		$query = new Query($this->connection);

		$query->delete()
			->from(['a ' => 'authors'])
			->where(['a.id !=' => 99]);

		$result = $query->sql();
		$this->assertQuotedQuery('DELETE FROM <authors> WHERE <id> != :c0', $result, true);

		$result = $query->execute();
		$this->assertInstanceOf('Cake\Database\StatementInterface', $result);
		$this->assertCount(self::AUTHOR_COUNT, $result);
	}

/**
 * Test a basic delete with no from() call.
 *
 * @return void
 */
	public function testDeleteNoFrom() {
		$query = new Query($this->connection);

		$query->delete('authors')
			->where('1 = 1');

		$result = $query->sql();
		$this->assertQuotedQuery('DELETE FROM <authors>', $result, true);

		$result = $query->execute();
		$this->assertInstanceOf('Cake\Database\StatementInterface', $result);
		$this->assertCount(self::AUTHOR_COUNT, $result);
	}

/**
 * Test setting select() & delete() modes.
 *
 * @return void
 */
	public function testSelectAndDeleteOnSameQuery() {
		$query = new Query($this->connection);
		$result = $query->select()
			->delete('authors')
			->where('1 = 1');
		$result = $query->sql();

		$this->assertQuotedQuery('DELETE FROM <authors>', $result, true);
		$this->assertContains(' WHERE 1 = 1', $result);
	}

/**
 * Test a simple update.
 *
 * @return void
 */
	public function testUpdateSimple() {
		$query = new Query($this->connection);
		$query->update('authors')
			->set('name', 'mark')
			->where(['id' => 1]);
		$result = $query->sql();
		$this->assertQuotedQuery('UPDATE <authors> SET <name> = :', $result, true);

		$result = $query->execute();
		$this->assertCount(1, $result);
	}

/**
 * Test update with multiple fields.
 *
 * @return void
 */
	public function testUpdateMultipleFields() {
		$query = new Query($this->connection);
		$query->update('articles')
			->set('title', 'mark', 'string')
			->set('body', 'some text', 'string')
			->where(['id' => 1]);
		$result = $query->sql();

		$this->assertQuotedQuery(
			'UPDATE <articles> SET <title> = :c0 , <body> = :c1',
			$result,
			true
		);

		$this->assertQuotedQuery(' WHERE <id> = :c2$', $result, true);
		$result = $query->execute();
		$this->assertCount(1, $result);
	}

/**
 * Test updating multiple fields with an array.
 *
 * @return void
 */
	public function testUpdateMultipleFieldsArray() {
		$query = new Query($this->connection);
		$query->update('articles')
			->set([
				'title' => 'mark',
				'body' => 'some text'
			], ['title' => 'string', 'body' => 'string'])
			->where(['id' => 1]);
		$result = $query->sql();

		$this->assertQuotedQuery(
			'UPDATE <articles> SET <title> = :c0 , <body> = :c1',
			$result,
			true
		);
		$this->assertQuotedQuery('WHERE <id> = :', $result, true);

		$result = $query->execute();
		$this->assertCount(1, $result);
	}

/**
 * Test updates with an expression.
 *
 * @return void
 */
	public function testUpdateWithExpression() {
		$query = new Query($this->connection);

		$expr = $query->newExpr('title = author_id');

		$query->update('articles')
			->set($expr)
			->where(['id' => 1]);
		$result = $query->sql();

		$this->assertQuotedQuery(
			'UPDATE <articles> SET title = author_id WHERE <id> = :',
			$result,
			true
		);

		$result = $query->execute();
		$this->assertCount(1, $result);
	}

/**
 * Test update with array fields and types.
 *
 * @return void
 */
	public function testUpdateArrayFields() {
		$query = new Query($this->connection);
		$date = new \DateTime;
		$query->update('comments')
			->set(['comment' => 'mark', 'created' => $date], ['created' => 'date'])
			->where(['id' => 1]);
		$result = $query->sql();

		$this->assertQuotedQuery(
			'UPDATE <comments> SET <comment> = :c0 , <created> = :c1',
			$result,
			true
		);

		$this->assertQuotedQuery(' WHERE <id> = :c2$', $result, true);
		$result = $query->execute();
		$this->assertCount(1, $result);

		$query = new Query($this->connection);
		$result = $query->select('created')->from('comments')->where(['id' => 1])->execute();
		$result = $result->fetchAll('assoc')[0]['created'];
		$this->assertStringStartsWith($date->format('Y-m-d'), $result);
	}

/**
 * You cannot call values() before insert() it causes all sorts of pain.
 *
 * @expectedException \Cake\Error\Exception
 * @return void
 */
	public function testInsertValuesBeforeInsertFailure() {
		$query = new Query($this->connection);
		$query->select('*')->values([
				'id' => 1,
				'title' => 'mark',
				'body' => 'test insert'
			]);
	}

/**
 * Inserting nothing should not generate an error.
 *
 * @expectedException RuntimeException
 * @expectedExceptionMessage At least 1 column is required to perform an insert.
 * @return void
 */
	public function testInsertNothing() {
		$query = new Query($this->connection);
		$query->insert([]);
	}

/**
 * Test inserting a single row.
 *
 * @return void
 */
	public function testInsertSimple() {
		$query = new Query($this->connection);
		$query->insert(['title', 'body'])
			->into('articles')
			->values([
				'title' => 'mark',
				'body' => 'test insert'
			]);
		$result = $query->sql();
		$this->assertQuotedQuery(
			'INSERT INTO <articles> \(<title>, <body>\) ' .
			'VALUES \(\?, \?\)',
			$result,
			true
		);

		$result = $query->execute();
		$this->assertCount(1, $result, '1 row should be inserted');

		$expected = [
			[
				'id' => 4,
				'author_id' => null,
				'title' => 'mark',
				'body' => 'test insert',
				'published' => 'N',
			]
		];
		$this->assertTable('articles', 1, $expected, ['id >=' => 4]);
	}

/**
 * Test an insert when not all the listed fields are provided.
 * Columns should be matched up where possible.
 *
 * @return void
 */
	public function testInsertSparseRow() {
		$query = new Query($this->connection);
		$query->insert(['title', 'body'])
			->into('articles')
			->values([
				'title' => 'mark',
			]);
		$result = $query->sql();
		$this->assertQuotedQuery(
			'INSERT INTO <articles> \(<title>, <body>\) ' .
			'VALUES \(\?, \?\)',
			$result,
			true
		);

		$result = $query->execute();
		$this->assertCount(1, $result, '1 row should be inserted');

		$expected = [
			[
				'id' => 4,
				'author_id' => null,
				'title' => 'mark',
				'body' => null,
				'published' => 'N',
			]
		];
		$this->assertTable('articles', 1, $expected, ['id >= 4']);
	}

/**
 * Test inserting multiple rows with sparse data.
 *
 * @return void
 */
	public function testInsertMultipleRowsSparse() {
		$query = new Query($this->connection);
		$query->insert(['title', 'body'])
			->into('articles')
			->values([
				'body' => 'test insert'
			])
			->values([
				'title' => 'jose',
			]);

		$result = $query->execute();
		$this->assertCount(2, $result, '2 rows should be inserted');

		$expected = [
			[
				'id' => 4,
				'author_id' => null,
				'title' => null,
				'body' => 'test insert',
				'published' => 'N',
			],
			[
				'id' => 5,
				'author_id' => null,
				'title' => 'jose',
				'body' => null,
				'published' => 'N',
			],
		];
		$this->assertTable('articles', 2, $expected, ['id >=' => 4]);
	}

/**
 * Test that INSERT INTO ... SELECT works.
 *
 * @return void
 */
	public function testInsertFromSelect() {
		$select = (new Query($this->connection))->select(['name', "'some text'", 99])
			->from('authors')
			->where(['id' => 1]);

		$query = new Query($this->connection);
		$query->insert(
			['title', 'body', 'author_id'],
			['title' => 'string', 'body' => 'string', 'author_id' => 'integer']
		)
		->into('articles')
		->values($select);

		$result = $query->sql();
		$this->assertQuotedQuery(
			'INSERT INTO <articles> \(<title>, <body>, <author_id>\) SELECT',
			$result,
			true
		);
		$this->assertQuotedQuery(
			'SELECT <name>, \'some text\', 99 FROM <authors>', $result, true);
		$result = $query->execute();

		$this->assertCount(1, $result);
		$result = (new Query($this->connection))->select('*')
			->from('articles')
			->where(['author_id' => 99])
			->execute();
		$this->assertCount(1, $result);
		$expected = [
			'id' => 4,
			'title' => 'mariano',
			'body' => 'some text',
			'author_id' => 99,
			'published' => 'N',
		];
		$this->assertEquals($expected, $result->fetch('assoc'));
	}

/**
 * Test that an exception is raised when mixing query + array types.
 *
 * @expectedException \Cake\Error\Exception
 */
	public function testInsertFailureMixingTypesArrayFirst() {
		$query = new Query($this->connection);
		$query->insert(['name'])
			->into('articles')
			->values(['name' => 'mark'])
			->values(new Query($this->connection));
	}

/**
 * Test that an exception is raised when mixing query + array types.
 *
 * @expectedException \Cake\Error\Exception
 */
	public function testInsertFailureMixingTypesQueryFirst() {
		$query = new Query($this->connection);
		$query->insert(['name'])
			->into('articles')
			->values(new Query($this->connection))
			->values(['name' => 'mark']);
	}

/**
 * Tests that functions are correctly transformed and their parameters are bound
 *
 * @group FunctionExpression
 * @return void
 */
	public function testSQLFunctions() {
		$query = new Query($this->connection);
		$result = $query->select(
				function($q) {
					return ['total' => $q->func()->count('*')];
				}
			)
			->from('articles')
			->execute();
		$expected = [['total' => 3]];
		$this->assertEquals($expected, $result->fetchAll('assoc'));

		$query = new Query($this->connection);
		$result = $query->select([
				'c' => $query->func()->concat(['title' => 'literal', ' is appended'])
			])
			->from('articles')
			->order(['c' => 'ASC'])
			->execute();
		$expected = [
			['c' => 'First Article is appended'],
			['c' => 'Second Article is appended'],
			['c' => 'Third Article is appended']
		];
		$this->assertEquals($expected, $result->fetchAll('assoc'));

		$query = new Query($this->connection);
		$result = $query
			->select(['d' => $query->func()->dateDiff(['2012-01-05', '2012-01-02'])])
			->execute()
			->fetchAll('assoc');
		$this->assertEquals(3, abs($result[0]['d']));

		$query = new Query($this->connection);
		$result = $query
			->select(['d' => $query->func()->now('date')])
			->execute();
		$this->assertEquals([['d' => date('Y-m-d')]], $result->fetchAll('assoc'));

		$query = new Query($this->connection);
		$result = $query
			->select(['d' => $query->func()->now('time')])
			->execute();

		$this->assertWithinRange(
			date('U'),
			(new \DateTime($result->fetchAll('assoc')[0]['d']))->format('U'),
			1
		);

		$query = new Query($this->connection);
		$result = $query
			->select(['d' => $query->func()->now()])
			->execute();
		$this->assertWithinRange(
			date('U'),
			(new \DateTime($result->fetchAll('assoc')[0]['d']))->format('U'),
			1
		);
	}

/**
 * Tests that default types are passed to functions accepting a $types param
 *
 * @return void
 */
	public function testDefaultTypes() {
		$query = new Query($this->connection);
		$this->assertEquals([], $query->defaultTypes());
		$types = ['id' => 'integer', 'created' => 'datetime'];
		$this->assertSame($query, $query->defaultTypes($types));
		$this->assertSame($types, $query->defaultTypes());

		$results = $query->select(['id', 'comment'])
			->from('comments')
			->where(['created >=' => new \DateTime('2007-03-18 10:55:00')])
			->execute();
		$expected = [['id' => '6', 'comment' => 'Second Comment for Second Article']];
		$this->assertEquals($expected, $results->fetchAll('assoc'));

		// Now test default can be overridden
		$types = ['created' => 'date'];
		$results = $query
			->where(['created >=' => new \DateTime('2007-03-18 10:50:00')], $types, true)
			->execute();
		$this->assertCount(6, $results, 'All 6 rows should match.');
	}

/**
 * Tests parameter binding
 *
 * @return void
 */
	public function testBind() {
		$query = new Query($this->connection);
		$results = $query->select(['id', 'comment'])
			->from('comments')
			->where(['created BETWEEN :foo AND :bar'])
			->bind(':foo', new \DateTime('2007-03-18 10:50:00'), 'datetime')
			->bind(':bar', new \DateTime('2007-03-18 10:52:00'), 'datetime')
			->execute();
		$expected = [['id' => '4', 'comment' => 'Fourth Comment for First Article']];
		$this->assertEquals($expected, $results->fetchAll('assoc'));

		$query = new Query($this->connection);
		$results = $query->select(['id', 'comment'])
			->from('comments')
			->where(['created BETWEEN :foo AND :bar'])
			->bind(':foo', '2007-03-18 10:50:00')
			->bind(':bar', '2007-03-18 10:52:00')
			->execute();
		$this->assertEquals($expected, $results->fetchAll('assoc'));
	}

/**
 * Test that epilog() will actually append a string to a select query
 *
 * @return void
 */
	public function testAppendSelect() {
		$query = new Query($this->connection);
		$sql = $query
			->select(['id', 'title'])
			->from('articles')
			->where(['id' => 1])
			->epilog('FOR UPDATE')
			->sql();
		$this->assertContains('SELECT', $sql);
		$this->assertContains('FROM', $sql);
		$this->assertContains('WHERE', $sql);
		$this->assertEquals(' FOR UPDATE', substr($sql, -11));
	}

/**
 * Test that epilog() will actually append a string to an insert query
 *
 * @return void
 */
	public function testAppendInsert() {
		$query = new Query($this->connection);
		$sql = $query
			->insert(['id', 'title'])
			->into('articles')
			->values([1, 'a title'])
			->epilog('RETURNING id')
			->sql();
		$this->assertContains('INSERT', $sql);
		$this->assertContains('INTO', $sql);
		$this->assertContains('VALUES', $sql);
		$this->assertEquals(' RETURNING id', substr($sql, -13));
	}

/**
 * Test that epilog() will actually append a string to an update query
 *
 * @return void
 */
	public function testAppendUpdate() {
		$query = new Query($this->connection);
		$sql = $query
			->update('articles')
			->set(['title' => 'foo'])
			->where(['id' => 1])
			->epilog('RETURNING id')
			->sql();
		$this->assertContains('UPDATE', $sql);
		$this->assertContains('SET', $sql);
		$this->assertContains('WHERE', $sql);
		$this->assertEquals(' RETURNING id', substr($sql, -13));
	}

/**
 * Test that epilog() will actually append a string to a delete query
 *
 * @return void
 */
	public function testAppendDelete() {
		$query = new Query($this->connection);
		$sql = $query
			->delete('articles')
			->where(['id' => 1])
			->epilog('RETURNING id')
			->sql();
		$this->assertContains('DELETE FROM', $sql);
		$this->assertContains('WHERE', $sql);
		$this->assertEquals(' RETURNING id', substr($sql, -13));
	}

/**
 * Tests automatic identifier quoting in the select clause
 *
 * @return void
 */
	public function testQuotingSelectFieldsAndAlias() {
		$this->connection->driver()->autoQuoting(true);
		$query = new Query($this->connection);
		$sql = $query->select(['something'])->sql();
		$this->assertQuotedQuery('SELECT <something>$', $sql);

		$query = new Query($this->connection);
		$sql = $query->select(['foo' => 'something'])->sql();
		$this->assertQuotedQuery('SELECT <something> AS <foo>$', $sql);

		$query = new Query($this->connection);
		$sql = $query->select(['foo' => 1])->sql();
		$this->assertQuotedQuery('SELECT 1 AS <foo>$', $sql);

		$query = new Query($this->connection);
		$sql = $query->select(['foo' => '1 + 1'])->sql();
		$this->assertQuotedQuery('SELECT <1 \+ 1> AS <foo>$', $sql);

		$query = new Query($this->connection);
		$sql = $query->select(['foo' => $query->newExpr('1 + 1')])->sql();
		$this->assertQuotedQuery('SELECT \(1 \+ 1\) AS <foo>$', $sql);

		$query = new Query($this->connection);
		$sql = $query->select(['foo' => new IdentifierExpression('bar')])->sql();
		$this->assertQuotedQuery('<bar>', $sql);
	}

/**
 * Tests automatic identifier quoting in the from clause
 *
 * @return void
 */
	public function testQuotingFromAndAlias() {
		$this->connection->driver()->autoQuoting(true);
		$query = new Query($this->connection);
		$sql = $query->select('*')->from(['something'])->sql();
		$this->assertQuotedQuery('FROM <something>', $sql);

		$query = new Query($this->connection);
		$sql = $query->select('*')->from(['foo' => 'something'])->sql();
		$this->assertQuotedQuery('FROM <something> AS <foo>$', $sql);

		$query = new Query($this->connection);
		$sql = $query->select('*')->from(['foo' => $query->newExpr('bar')])->sql();
		$this->assertQuotedQuery('FROM \(bar\) AS <foo>$', $sql);
	}

/**
 * Tests automatic identifier quoting for DISTINCT ON
 *
 * @return void
 */
	public function testQuotingDistinctOn() {
		$this->connection->driver()->autoQuoting(true);
		$query = new Query($this->connection);
		$sql = $query->select('*')->distinct(['something'])->sql();
		$this->assertQuotedQuery('<something>', $sql);
	}

/**
 * Tests automatic identifier quoting in the join clause
 *
 * @return void
 */
	public function testQuotingJoinsAndAlias() {
		$this->connection->driver()->autoQuoting(true);
		$query = new Query($this->connection);
		$sql = $query->select('*')->join(['something'])->sql();
		$this->assertQuotedQuery('JOIN <something>', $sql);

		$query = new Query($this->connection);
		$sql = $query->select('*')->join(['foo' => 'something'])->sql();
		$this->assertQuotedQuery('JOIN <something> <foo>', $sql);

		$query = new Query($this->connection);
		$sql = $query->select('*')->join(['foo' => $query->newExpr('bar')])->sql();
		$this->assertQuotedQuery('JOIN \(bar\) <foo>', $sql);
	}

/**
 * Tests automatic identifier quoting in the group by clause
 *
 * @return void
 */
	public function testQuotingGroupBy() {
		$this->connection->driver()->autoQuoting(true);
		$query = new Query($this->connection);
		$sql = $query->select('*')->group(['something'])->sql();
		$this->assertQuotedQuery('GROUP BY <something>', $sql);

		$query = new Query($this->connection);
		$sql = $query->select('*')->group([$query->newExpr('bar')])->sql();
		$this->assertQuotedQuery('GROUP BY \(bar\)', $sql);

		$query = new Query($this->connection);
		$sql = $query->select('*')->group([new IdentifierExpression('bar')])->sql();
		$this->assertQuotedQuery('GROUP BY \(<bar>\)', $sql);
	}

/**
 * Tests automatic identifier quoting strings inside conditions expressions
 *
 * @return void
 */
	public function testQuotingExpressions() {
		$this->connection->driver()->autoQuoting(true);
		$query = new Query($this->connection);
		$sql = $query->select('*')
			->where(['something' => 'value'])
			->sql();
		$this->assertQuotedQuery('WHERE <something> = :c0', $sql);

		$query = new Query($this->connection);
		$sql = $query->select('*')
			->where([
				'something' => 'value',
				'OR' => ['foo' => 'bar', 'baz' => 'cake']
			])
			->sql();
		$this->assertQuotedQuery('<something> = :c0 AND', $sql);
		$this->assertQuotedQuery('\(<foo> = :c1 OR <baz> = :c2\)', $sql);
	}

/**
 * Tests that insert query parts get quoted automatically
 *
 * @return void
 */
	public function testQuotingInsert() {
		$this->connection->driver()->autoQuoting(true);
		$query = new Query($this->connection);
		$sql = $query->insert(['bar', 'baz'])
			->into('foo')
			->where(['something' => 'value'])
			->sql();
		$this->assertQuotedQuery('INSERT INTO <foo> \(<bar>, <baz>\)', $sql);

		$query = new Query($this->connection);
		$sql = $query->insert([$query->newExpr('bar')])
			->into('foo')
			->where(['something' => 'value'])
			->sql();
		$this->assertQuotedQuery('INSERT INTO <foo> \(\(bar\)\)', $sql);
	}

/**
 * Tests converting a query to a string
 *
 * @return void
 */
	public function testToString() {
		$query = new Query($this->connection);
		$query
			->select(['title'])
			->from('articles');
		$result = (string)$query;
		$this->assertQuotedQuery('SELECT <title> FROM <articles>', $result, true);
	}

/**
 * Tests __debugInfo
 *
 * @return void
 */
	public function testDebugInfo() {
		$query = (new Query($this->connection))->select('*')
			->from('articles')
			->defaultTypes(['id' => 'integer'])
			->where(['id' => '1']);

		$expected = [
			'sql' => $query->sql(),
			'params' => [
				':c0' => ['value' => '1', 'type' => 'integer', 'placeholder' => 'c0']
			],
			'defaultTypes' => ['id' => 'integer'],
			'decorators' => 0,
			'executed' => false
		];
		$result = $query->__debugInfo();
		$this->assertEquals($expected, $result);

		$query->execute();
		$expected = [
			'sql' => $query->sql(),
			'params' => [
				':c0' => ['value' => '1', 'type' => 'integer', 'placeholder' => 'c0']
			],
			'defaultTypes' => ['id' => 'integer'],
			'decorators' => 0,
			'executed' => true
		];
		$result = $query->__debugInfo();
		$this->assertEquals($expected, $result);
	}

/**
 * Tests that it is possible to pass ExpressionInterface to isNull and isNotNull
 *
 * @return void
 */
	public function testIsNullWithExpressions() {
		$query = new Query($this->connection);
		$subquery = (new Query($this->connection))
			->select(['id'])
			->from('authors')
			->where(['id' => 1]);

		$result = $query
			->select(['name'])
			->from(['authors'])
			->where(function($exp) use ($subquery) {
				return $exp->isNotNull($subquery);
			})
			->execute();
		$this->assertNotEmpty($result->fetchAll('assoc'));

		$result = (new Query($this->connection))
			->select(['name'])
			->from(['authors'])
			->where(function($exp) use ($subquery) {
				return $exp->isNull($subquery);
			})
			->execute();
		$this->assertEmpty($result->fetchAll('assoc'));
	}

/**
 * Tests that strings passed to isNull and isNotNull will be treated as identifiers
 * when using autoQuoting
 *
 * @return void
 */
	public function testIsNullAutoQuoting() {
		$this->connection->driver()->autoQuoting(true);
		$query = new Query($this->connection);
		$query->select('*')->from('things')->where(function($exp) {
			return $exp->isNull('field');
		});
		$this->assertQuotedQuery('WHERE \(<field>\) IS NULL', $query->sql());

		$query = new Query($this->connection);
		$query->select('*')->from('things')->where(function($exp) {
			return $exp->isNotNull('field');
		});
		$this->assertQuotedQuery('WHERE \(<field>\) IS NOT NULL', $query->sql());
	}

/**
 * Tests that using the IS operator will automatically translate to the best
 * possible operator depending on the passed value
 *
 * @return void
 */
	public function testDirectIsNull() {
		$sql = (new Query($this->connection))
			->select(['name'])
			->from(['authors'])
			->where(['name IS' => null])
			->sql();
		$this->assertQuotedQuery('WHERE \(<name>\) IS NULL', $sql, true);

		$results = (new Query($this->connection))
			->select(['name'])
			->from(['authors'])
			->where(['name IS' => 'larry'])
			->execute();
		$this->assertCount(1, $results);
		$this->assertEquals(['name' => 'larry'], $results->fetch('assoc'));
	}

/**
 * Tests that case statements work correctly for various use-cases.
 *
 * @return void
 */
	public function testSqlCaseStatement() {
		$query = new Query($this->connection);
		$publishedCase = $query
			->newExpr()
			->addCase($query
				->newExpr()
				->add(['published' => 'Y']), 1, 'integer'
			);
		$notPublishedCase = $query
			->newExpr()
			->addCase($query
					->newExpr()
					->add(['published' => 'N']), 1, 'integer'
			);

		//Postgres requires the case statement to be cast to a integer
		if ($this->connection->driver() instanceof \Cake\Database\Driver\Postgres) {
			$publishedCase = $query->func()->cast([$publishedCase, 'integer' => 'literal'])->type(' AS ');
			$notPublishedCase = $query->func()->cast([$notPublishedCase, 'integer' => 'literal'])->type(' AS ');
		}

		$results = $query
			->select([
				'published' => $query->func()->sum($publishedCase),
				'not_published' => $query->func()->sum($notPublishedCase)
			])
			->from(['comments'])
			->execute()
			->fetchAll('assoc');

		$this->assertEquals(5, $results[0]['published']);
		$this->assertEquals(1, $results[0]['not_published']);

		$query = new Query($this->connection);
		$query
			->insert(['article_id', 'user_id', 'comment', 'published'])
			->into('comments')
			->values([
				'article_id' => 2,
				'user_id' => 1,
				'comment' => 'In limbo',
				'published' => 'L'
			])
			->execute();

		$query = new Query($this->connection);
		$conditions = [
			$query
				->newExpr()
				->add(['published' => 'Y']),
			$query
				->newExpr()
				->add(['published' => 'N'])
		];
		$values = [
			'Published',
			'Not published',
			'None'
		];
		$results = $query
			->select([
				'id',
				'comment',
				'status' => $query->newExpr()->addCase($conditions, $values)
			])
			->from(['comments'])
			->execute()
			->fetchAll('assoc');

		$this->assertEquals('Published', $results[2]['status']);
		$this->assertEquals('Not published', $results[3]['status']);
		$this->assertEquals('None', $results[6]['status']);
	}

/**
 * Assertion for comparing a table's contents with what is in it.
 *
 * @param string $table
 * @param int $count
 * @param array $rows
 * @param array $conditions
 * @return void
 */
	public function assertTable($table, $count, $rows, $conditions = []) {
		$result = (new Query($this->connection))->select('*')
			->from($table)
			->where($conditions)
			->execute();
		$this->assertCount($count, $result, 'Row count is incorrect');
		$this->assertEquals($rows, $result->fetchAll('assoc'));
	}

/**
 * Assertion for comparing a regex pattern against a query having its indentifiers
 * quoted. It accepts queries quoted with the characters `<` and `>`. If the third
 * parameter is set to true, it will alter the pattern to both accept quoted and
 * unquoted queries
 *
 * @param string $pattern
 * @param string $query the result to compare against
 * @param bool $optional
 * @return void
 */
	public function assertQuotedQuery($pattern, $query, $optional = false) {
		if ($optional) {
			$optional = '?';
		}
		$pattern = str_replace('<', '[`"\[]' . $optional, $pattern);
		$pattern = str_replace('>', '[`"\]]' . $optional, $pattern);
		$this->assertRegExp('#' . $pattern . '#', $query);
	}

}
