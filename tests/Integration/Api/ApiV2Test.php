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
			'submit_once' => true,
			'questions' => [
				[
					'type' => 'short',
					'text' => 'First Question?',
					'isRequired' => true,
					'order' => 1,
					'options' => []
				],
				[
					'type' => 'multiple_unique',
					'text' => 'Second Question?',
					'isRequired' => false,
					'order' => 2,
					'options' => [
						[
							'text' => 'Option 1'
						],
						[
							'text' => 'Option 2'
						]
					]
				]
			],
			'shares' => [
				[
					'shareType' => 0,
					'shareWith' => 'user1',
				],
				[
					'shareType' => 3,
					'shareWith' => 'shareHash',
				],
			],
			'submissions' => [

			]
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
			'submit_once' => true,
			'questions' => [
				[
					'type' => 'short',
					'text' => 'Third Question?',
					'isRequired' => false,
					'order' => 1,
					'options' => []
				],
			],
			'shares' => [
				[
					'shareType' => 0,
					'shareWith' => 'user2',
				],
			],
			'submissions' => []
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
			$formId = $qb->getLastInsertId();
			$this->testForms[$index]['id'] = $formId;

			foreach ($form['questions'] as $qIndex => $question) {
				$qb->insert('forms_v2_questions')
					->values([
						'form_id' => $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT),
						'order' => $qb->createNamedParameter($question['order'], IQueryBuilder::PARAM_INT),
						'type' => $qb->createNamedParameter($question['type'], IQueryBuilder::PARAM_STR),
						'is_required' => $qb->createNamedParameter($question['isRequired'], IQueryBuilder::PARAM_BOOL),
						'text' => $qb->createNamedParameter($question['text'], IQueryBuilder::PARAM_STR)
					]);
				$qb->execute();
				$questionId = $qb->getLastInsertId();
				$this->testForms[$index]['questions'][$qIndex]['id'] = $questionId;

				foreach($question['options'] as $oIndex => $option) {
					$qb->insert('forms_v2_options')
						->values([
							'question_id' => $qb->createNamedParameter($questionId, IQueryBuilder::PARAM_INT),
							'text' => $qb->createNamedParameter($option['text'], IQueryBuilder::PARAM_STR)
						]);
					$qb->execute();
					$this->testForms[$index]['questions'][$qIndex]['options'][$oIndex]['id'] = $qb->getLastInsertId();
				}
			}

			foreach($form['shares'] as $sIndex => $share) {
				$qb->insert('forms_v2_shares')
					->values([
						'form_id' => $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT),
						'share_type' => $qb->createNamedParameter($share['shareType'], IQueryBuilder::PARAM_STR),
						'share_with' => $qb->createNamedParameter($share['shareWith'], IQueryBuilder::PARAM_STR)
					]);
				$qb->execute();
				$questionId =
				$this->testForms[$index]['shares'][$sIndex]['id'] = $qb->getLastInsertId();
			}
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

			foreach($form['questions'] as $question) {
				$qb->delete('forms_v2_questions')
					->where($qb->expr()->eq('id', $qb->createNamedParameter($question['id'], IQueryBuilder::PARAM_INT)));
				$qb->execute();

				foreach($question['options'] as $option) {
					$qb->delete('forms_v2_options')
						->where($qb->expr()->eq('id', $qb->createNamedParameter($option['id'], IQueryBuilder::PARAM_INT)));
					$qb->execute();
				}
			}

			foreach ($form['shares'] as $share) {
				$qb->delete('forms_v2_shares')
					->where($qb->expr()->eq('id', $qb->createNamedParameter($share['id'], IQueryBuilder::PARAM_INT)));
				$qb->execute();
			}
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
					'questions' => [
						[
							'type' => 'short',
							'text' => 'First Question?',
							'isRequired' => true,
							'order' => 1,
							'options' => []
						],
						[
							'type' => 'multiple_unique',
							'text' => 'Second Question?',
							'isRequired' => false,
							'order' => 2,
							'options' => [
								[
									'text' => 'Option 1'
								],
								[
									'text' => 'Option 2'
								]
							]
						]
					],
					'shares' => [
						[
							'shareType' => 0,
							'shareWith' => 'user1',
							'displayName' => ''
						],
						[
							'shareType' => 3,
							'shareWith' => 'shareHash',
							'displayName' => ''
						],
					],
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

		// Cannot control ids, but check general consistency.
		foreach ($data['questions'] as $qIndex => $question) {
			$this->assertEquals($data['id'], $question['formId']);
			unset($data['questions'][$qIndex]['formId']);

			foreach ($question['options'] as $oIndex => $option) {
				$this->assertEquals($question['id'], $option['questionId']);
				unset($data['questions'][$qIndex]['options'][$oIndex]['questionId']);
				unset($data['questions'][$qIndex]['options'][$oIndex]['id']);
			}
			unset($data['questions'][$qIndex]['id']);
		}
		foreach ($data['shares'] as $sIndex => $share) {
			$this->assertEquals($data['id'], $share['formId']);
			unset($data['shares'][$sIndex]['formId']);
			unset($data['shares'][$sIndex]['id']);
		}
		unset($data['id']);

		$this->assertEquals(200, $resp->getStatusCode());
		$this->assertEquals($expected, $data);
	}
};
