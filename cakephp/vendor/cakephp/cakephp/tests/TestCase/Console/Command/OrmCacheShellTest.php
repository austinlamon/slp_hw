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
namespace Cake\Test\TestCase\Console\Command;

use Cake\Cache\Cache;
use Cake\Console\Command\OrmCacheShell;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * OrmCacheShell test.
 */
class OrmCacheShellTest extends TestCase {

/**
 * Fixtures.
 *
 * @var array
 */
	public $fixtures = ['core.article', 'core.tag'];

/**
 * setup method
 *
 * @return void
 */
	public function setUp() {
		parent::setUp();
		$this->io = $this->getMock('Cake\Console\ConsoleIo');
		$this->shell = new OrmCacheShell($this->io);

		$this->cache = $this->getMock('Cake\Cache\CacheEngine');
		$this->cache->expects($this->any())
			->method('init')
			->will($this->returnValue(true));
		Cache::config('orm_cache', $this->cache);

		$ds = ConnectionManager::get('test');
		$ds->cacheMetadata('orm_cache');
	}

/**
 * Teardown
 *
 * @return void
 */
	public function tearDown() {
		parent::tearDown();
		Cache::drop('orm_cache');

		$ds = ConnectionManager::get('test');
		$ds->cacheMetadata(false);
	}

/**
 * Test build() with no args.
 *
 * @return void
 */
	public function testBuildNoArgs() {
		$this->cache->expects($this->at(2))
			->method('write')
			->with('test_articles');

		$this->shell->params['connection'] = 'test';
		$this->shell->build();
	}

/**
 * Test build() with one arg.
 *
 * @return void
 */
	public function testBuildNamedModel() {
		$this->cache->expects($this->once())
			->method('write')
			->with('test_articles');
		$this->cache->expects($this->never())
			->method('delete');

		$this->shell->params['connection'] = 'test';
		$this->shell->build('articles');
	}

/**
 * Test build() overwrites cached data.
 *
 * @return void
 */
	public function testBuildOverwritesExistingData() {
		$this->cache->expects($this->once())
			->method('write')
			->with('test_articles');
		$this->cache->expects($this->never())
			->method('read');
		$this->cache->expects($this->never())
			->method('delete');

		$this->shell->params['connection'] = 'test';
		$this->shell->build('articles');
	}

/**
 * Test build() with a non-existing connection name.
 *
 * @expectedException Cake\Datasource\Error\MissingDatasourceConfigException
 * @return void
 */
	public function testBuildInvalidConnection() {
		$this->shell->params['connection'] = 'derpy-derp';
		$this->shell->build('articles');
	}

/**
 * Test clear() with an invalid connection name.
 *
 * @expectedException Cake\Datasource\Error\MissingDatasourceConfigException
 * @return void
 */
	public function testClearInvalidConnection() {
		$this->shell->params['connection'] = 'derpy-derp';
		$this->shell->clear('articles');
	}

/**
 * Test clear() with no args.
 *
 * @return void
 */
	public function testClearNoArgs() {
		$this->cache->expects($this->at(2))
			->method('delete')
			->with('test_articles');

		$this->shell->params['connection'] = 'test';
		$this->shell->clear();
	}

/**
 * Test clear() with a model name.
 *
 * @return void
 */
	public function testClearNamedModel() {
		$this->cache->expects($this->never())
			->method('write');
		$this->cache->expects($this->once())
			->method('delete')
			->with('test_articles');

		$this->shell->params['connection'] = 'test';
		$this->shell->clear('articles');
	}

}
