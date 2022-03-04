<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2022 Jonas Rittershofer <jotoeri@users.noreply.github.com>
 *
 * @author Jonas Rittershofer <jotoeri@users.noreply.github.com>
 *
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace OCA\Forms\Tests\Integration\Api;

use OCA\Forms\Db\Form;
use OCA\Forms\Db\FormMapper;

use OCP\DB\QueryBuilder\IQueryBuilder;

use GuzzleHttp\Client;
use Test\TestCase;

class ApiV2Test extends TestCase {
	/** @var GuzzleHttp\Client */
	private $http;

	/** @var FormMapper */
	private $formMapper;

	/** @var Array */
	private $testForms = [
		[
			'hash' => 'abcdefg',
			'title' => 'Title of a Form',
			'description' => 'Just a simple form.',
			'owner_id' => 'test',
			'access_json' => [
				'permitAllUsers' => false,
				'showToAllUsers' => false
			],
			'created' => 12345,
			'expires' => 0,
			'is_anonymous' => false,
			'submit_once' => true
		],
		[
			'hash' => 'abcdefghij',
			'title' => 'Title of a second Form',
			'description' => '',
			'owner_id' => 'someUser',
			'access_json' => [
				'permitAllUsers' => true,
				'showToAllUsers' => true
			],
			'created' => 12345,
			'expires' => 0,
			'is_anonymous' => false,
			'submit_once' => true
		]
	];

	/**
	 * Set up test environment.
	 * Writing testforms into db, preparing http request
	 */
	public function setUp(): void {
		parent::setUp();

		$qb = TestCase::$realDatabase->getQueryBuilder();

		// Write our test forms into db
		foreach ($this->testForms as $index => $form) {
			$qb->insert('forms_v2_forms')
				->values([
					'hash' => $qb->createNamedParameter($form['hash'], IQueryBuilder::PARAM_STR),
					'title' => $qb->createNamedParameter($form['title'], IQueryBuilder::PARAM_STR),
					'description' => $qb->createNamedParameter($form['description'], IQueryBuilder::PARAM_STR),
					'owner_id' => $qb->createNamedParameter($form['owner_id'], IQueryBuilder::PARAM_STR),
					'access_json' => $qb->createNamedParameter(json_encode($form['access_json']), IQueryBuilder::PARAM_STR),
					'created' => $qb->createNamedParameter($form['created'], IQueryBuilder::PARAM_INT),
					'expires' => $qb->createNamedParameter($form['expires'], IQueryBuilder::PARAM_INT),
					'is_anonymous' => $qb->createNamedParameter($form['is_anonymous'], IQueryBuilder::PARAM_BOOL),
					'submit_once' => $qb->createNamedParameter($form['submit_once'], IQueryBuilder::PARAM_BOOL)
				]);
			$qb->execute();

			$this->testForms[$index]['id'] = $qb->getLastInsertId();
		}

		// Set up http Client
		$this->http = new Client([
			'base_uri' => 'http://localhost:8080/ocs/v2.php/apps/forms/',
			'auth' => ['test', 'test'],
			'headers' => [
				'OCS-ApiRequest' => 'true',
				'Accept' => 'application/json'
			],
		]);
	}

	/** Clean up database from testforms */
	public function tearDown(): void {
		$qb = TestCase::$realDatabase->getQueryBuilder();

		foreach($this->testForms as $form) {
			$qb->delete('forms_v2_forms')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($form['id'], IQueryBuilder::PARAM_INT)));
			$qb->execute();
		}

		parent::tearDown();
	}

	// Small Wrapper for OCS-Response
	private function OcsResponse2Data($resp) {
		$arr = json_decode($resp->getBody()->getContents(), true);
		return $arr['ocs']['data'];
	}

	// Unset Id, as we can not control it on the tests.
	private function arrayUnsetId(array $arr): array {
		foreach ($arr as $index => $elem) {
			unset($arr[$index]['id']);
		}
		return $arr;
	}

	public function dataGetForms() {
		return [
			'getTestforms' => [
				'expected' => [[
					'hash' => 'abcdefg',
					'title' => 'Title of a Form',
					'expires' => 0,
					'permissions' => [
						'edit',
						'results',
						'submit'
					],
					'partial' => true
				]]
			]
		];
	}
	/**
	 * @dataProvider dataGetForms
	 * 
	 * @param array $expected
	 */
	public function testGetForms(array $expected): void {
		$resp = $this->http->request('GET', 'api/v2/forms');

		$data = $this->OcsResponse2Data($resp);
		$data = $this->arrayUnsetId($data);

		$this->assertEquals(200, $resp->getStatusCode());
		$this->assertEquals($expected, $data);
	}

	public function dataGetSharedForms() {
		return [
			'getTestforms' => [
				'expected' => [[
					'hash' => 'abcdefghij',
					'title' => 'Title of a second Form',
					'expires' => 0,
					'permissions' => [
						'submit'
					],
					'partial' => true
				]]
			]
		];
	}
	/**
	 * @dataProvider dataGetSharedForms
	 * 
	 * @param array $expected
	 */
	public function testGetSharedForms(array $expected): void {
		$resp = $this->http->request('GET', 'api/v2/shared_forms');

		$data = $this->OcsResponse2Data($resp);
		$data = $this->arrayUnsetId($data);

		$this->assertEquals(200, $resp->getStatusCode());
		$this->assertEquals($expected, $data);
	}

	public function dataGetNewForm() {
		return [
			'getNewForm' => [
				'expected' => [
					// 'hash' => Some random, cannot be checked.
					'title' => '',
					'description' => '',
					'ownerId' => 'test',
					// 'created' => Hard to check exactly.
					'access' => [
						'permitAllUsers' => false,
						'showToAllUsers' => false
					],
					'expires' => 0,
					'isAnonymous' => false,
					'submitOnce' => true,
					'canSubmit' => true,
					'permissions' => [
						'edit',
						'results',
						'submit'
					],
					'questions' => [],
					'shares' => [],
				]
			]
		];
	}
	/**
	 * @dataProvider dataGetNewForm
	 * 
	 * @param array $expected
	 */
	public function testGetNewForm(array $expected): void {
		$resp = $this->http->request('POST', 'api/v2/form');
		$data = $this->OcsResponse2Data($resp);

		// Store for deletion on tearDown
		$this->testForms[] = $data;

		// Cannot control id
		unset($data['id']);
		// Check general behaviour of hash
		$this->assertMatchesRegularExpression('/^[a-zA-Z0-9]{16}$/', $data['hash']);
		unset($data['hash']);
		// Check general behaviour of created (Created in the last 10 seconds)
		$this->assertTrue(time() - $data['created'] < 10);
		unset($data['created']);

		$this->assertEquals(200, $resp->getStatusCode());
		$this->assertEquals($expected, $data);
	}

	public function dataGetFullForm() {
		return [
			'getFullForm' => [
				'expected' => [
					'hash' => 'abcdefg',
					'title' => 'Title of a Form',
					'description' => 'Just a simple form.',
					'ownerId' => 'test',
					'created' => 12345,
					'access' => [
						'permitAllUsers' => false,
						'showToAllUsers' => false
					],
					'expires' => 0,
					'isAnonymous' => false,
					'submitOnce' => true,
					'canSubmit' => true,
					'permissions' => [
						'edit',
						'results',
						'submit'
					],
					'questions' => [],
					'shares' => [],
				]
			]
		];
	}
	/**
	 * @dataProvider dataGetFullForm
	 * 
	 * @param array $expected
	 */
	public function testGetFullForm(array $expected): void {
		$resp = $this->http->request('GET', "api/v2/form/{$this->testForms[0]['id']}");
		$data = $this->OcsResponse2Data($resp);

		// Cannot control id
		unset($data['id']);

		$this->assertEquals(200, $resp->getStatusCode());
		$this->assertEquals($expected, $data);
	}
};
