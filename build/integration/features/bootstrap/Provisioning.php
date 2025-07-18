<?php

/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use Behat\Gherkin\Node\TableNode;
use GuzzleHttp\Client;
use GuzzleHttp\Message\ResponseInterface;
use PHPUnit\Framework\Assert;

require __DIR__ . '/../../vendor/autoload.php';

trait Provisioning {
	use BasicStructure;

	/** @var array */
	private $createdUsers = [];

	/** @var array */
	private $createdRemoteUsers = [];

	/** @var array */
	private $createdRemoteGroups = [];

	/** @var array */
	private $createdGroups = [];

	/**
	 * @Given /^user "([^"]*)" exists$/
	 * @param string $user
	 */
	public function assureUserExists($user) {
		try {
			$this->userExists($user);
		} catch (\GuzzleHttp\Exception\ClientException $ex) {
			$previous_user = $this->currentUser;
			$this->currentUser = 'admin';
			$this->creatingTheUser($user);
			$this->currentUser = $previous_user;
		}
		$this->userExists($user);
		Assert::assertEquals(200, $this->response->getStatusCode());
	}

	/**
	 * @Given /^user "([^"]*)" with displayname "((?:[^"]|\\")*)" exists$/
	 * @param string $user
	 */
	public function assureUserWithDisplaynameExists($user, $displayname) {
		try {
			$this->userExists($user);
		} catch (\GuzzleHttp\Exception\ClientException $ex) {
			$previous_user = $this->currentUser;
			$this->currentUser = 'admin';
			$this->creatingTheUser($user, $displayname);
			$this->currentUser = $previous_user;
		}
		$this->userExists($user);
		Assert::assertEquals(200, $this->response->getStatusCode());
	}

	/**
	 * @Given /^user "([^"]*)" does not exist$/
	 * @param string $user
	 */
	public function userDoesNotExist($user) {
		try {
			$this->userExists($user);
		} catch (\GuzzleHttp\Exception\ClientException $ex) {
			$this->response = $ex->getResponse();
			Assert::assertEquals(404, $ex->getResponse()->getStatusCode());
			return;
		}
		$previous_user = $this->currentUser;
		$this->currentUser = 'admin';
		$this->deletingTheUser($user);
		$this->currentUser = $previous_user;
		try {
			$this->userExists($user);
		} catch (\GuzzleHttp\Exception\ClientException $ex) {
			$this->response = $ex->getResponse();
			Assert::assertEquals(404, $ex->getResponse()->getStatusCode());
		}
	}

	public function creatingTheUser($user, $displayname = '') {
		$fullUrl = $this->baseUrl . "v{$this->apiVersion}.php/cloud/users";
		$client = new Client();
		$options = [];
		if ($this->currentUser === 'admin') {
			$options['auth'] = $this->adminUser;
		}

		$options['form_params'] = [
			'userid' => $user,
			'password' => '123456'
		];
		if ($displayname !== '') {
			$options['form_params']['displayName'] = $displayname;
		}
		$options['headers'] = [
			'OCS-APIREQUEST' => 'true',
		];

		$this->response = $client->post($fullUrl, $options);
		if ($this->currentServer === 'LOCAL') {
			$this->createdUsers[$user] = $user;
		} elseif ($this->currentServer === 'REMOTE') {
			$this->createdRemoteUsers[$user] = $user;
		}

		//Quick hack to login once with the current user
		$options2 = [
			'auth' => [$user, '123456'],
		];
		$options2['headers'] = [
			'OCS-APIREQUEST' => 'true',
		];
		$url = $fullUrl . '/' . $user;
		$client->get($url, $options2);
	}

	/**
	 * @Then /^user "([^"]*)" has$/
	 *
	 * @param string $user
	 * @param TableNode|null $settings
	 */
	public function userHasSetting($user, $settings) {
		$fullUrl = $this->baseUrl . "v{$this->apiVersion}.php/cloud/users/$user";
		$client = new Client();
		$options = [];
		if ($this->currentUser === 'admin') {
			$options['auth'] = $this->adminUser;
		} else {
			$options['auth'] = [$this->currentUser, $this->regularUser];
		}
		$options['headers'] = [
			'OCS-APIREQUEST' => 'true',
		];

		$response = $client->get($fullUrl, $options);
		foreach ($settings->getRows() as $setting) {
			$value = json_decode(json_encode(simplexml_load_string($response->getBody())->data->{$setting[0]}), 1);
			if (isset($value['element']) && in_array($setting[0], ['additional_mail', 'additional_mailScope'], true)) {
				$expectedValues = explode(';', $setting[1]);
				foreach ($expectedValues as $expected) {
					Assert::assertTrue(in_array($expected, $value['element'], true), 'Data wrong for field: ' . $setting[0]);
				}
			} elseif (isset($value[0])) {
				Assert::assertEqualsCanonicalizing($setting[1], $value[0], 'Data wrong for field: ' . $setting[0]);
			} else {
				Assert::assertEquals('', $setting[1], 'Data wrong for field: ' . $setting[0]);
			}
		}
	}

	/**
	 * @Then /^user "([^"]*)" has the following profile data$/
	 */
	public function userHasProfileData(string $user, ?TableNode $settings): void {
		$fullUrl = $this->baseUrl . "v{$this->apiVersion}.php/profile/$user";
		$client = new Client();
		$options = [];
		if ($this->currentUser === 'admin') {
			$options['auth'] = $this->adminUser;
		} else {
			$options['auth'] = [$this->currentUser, $this->regularUser];
		}
		$options['headers'] = [
			'OCS-APIREQUEST' => 'true',
			'Accept' => 'application/json',
		];

		$response = $client->get($fullUrl, $options);
		$body = $response->getBody()->getContents();
		$data = json_decode($body, true);
		$data = $data['ocs']['data'];
		foreach ($settings->getRows() as $setting) {
			Assert::assertArrayHasKey($setting[0], $data, 'Profile data field missing: ' . $setting[0]);
			if ($setting[1] === 'NULL') {
				Assert::assertNull($data[$setting[0]], 'Profile data wrong for field: ' . $setting[0]);
			} else {
				Assert::assertEquals($setting[1], $data[$setting[0]], 'Profile data wrong for field: ' . $setting[0]);
			}
		}
	}

	/**
	 * @Then /^group "([^"]*)" has$/
	 *
	 * @param string $user
	 * @param TableNode|null $settings
	 */
	public function groupHasSetting($group, $settings) {
		$fullUrl = $this->baseUrl . "v{$this->apiVersion}.php/cloud/groups/details?search=$group";
		$client = new Client();
		$options = [];
		if ($this->currentUser === 'admin') {
			$options['auth'] = $this->adminUser;
		} else {
			$options['auth'] = [$this->currentUser, $this->regularUser];
		}
		$options['headers'] = [
			'OCS-APIREQUEST' => 'true',
		];

		$response = $client->get($fullUrl, $options);
		$groupDetails = simplexml_load_string($response->getBody())->data[0]->groups[0]->element;
		foreach ($settings->getRows() as $setting) {
			$value = json_decode(json_encode($groupDetails->{$setting[0]}), 1);
			if (isset($value[0])) {
				Assert::assertEqualsCanonicalizing($setting[1], $value[0]);
			} else {
				Assert::assertEquals('', $setting[1]);
			}
		}
	}


	/**
	 * @Then /^user "([^"]*)" has editable fields$/
	 *
	 * @param string $user
	 * @param TableNode|null $fields
	 */
	public function userHasEditableFields($user, $fields) {
		$fullUrl = $this->baseUrl . "v{$this->apiVersion}.php/cloud/user/fields";
		if ($user !== 'self') {
			$fullUrl .= '/' . $user;
		}
		$client = new Client();
		$options = [];
		if ($this->currentUser === 'admin') {
			$options['auth'] = $this->adminUser;
		} else {
			$options['auth'] = [$this->currentUser, $this->regularUser];
		}
		$options['headers'] = [
			'OCS-APIREQUEST' => 'true',
		];

		$response = $client->get($fullUrl, $options);
		$fieldsArray = json_decode(json_encode(simplexml_load_string($response->getBody())->data->element), 1);

		$expectedFields = $fields->getRows();
		$expectedFields = $this->simplifyArray($expectedFields);
		Assert::assertEquals($expectedFields, $fieldsArray);
	}

	/**
	 * @Then /^search users by phone for region "([^"]*)" with$/
	 *
	 * @param string $user
	 * @param TableNode|null $settings
	 */
	public function searchUserByPhone($region, TableNode $searchTable) {
		$fullUrl = $this->baseUrl . "v{$this->apiVersion}.php/cloud/users/search/by-phone";
		$client = new Client();
		$options = [];
		$options['auth'] = $this->adminUser;
		$options['headers'] = [
			'OCS-APIREQUEST' => 'true',
		];

		$search = [];
		foreach ($searchTable->getRows() as $row) {
			if (!isset($search[$row[0]])) {
				$search[$row[0]] = [];
			}
			$search[$row[0]][] = $row[1];
		}

		$options['form_params'] = [
			'location' => $region,
			'search' => $search,
		];

		$this->response = $client->post($fullUrl, $options);
	}

	public function createUser($user) {
		$previous_user = $this->currentUser;
		$this->currentUser = 'admin';
		$this->creatingTheUser($user);
		$this->userExists($user);
		$this->currentUser = $previous_user;
	}

	public function deleteUser($user) {
		$previous_user = $this->currentUser;
		$this->currentUser = 'admin';
		$this->deletingTheUser($user);
		$this->userDoesNotExist($user);
		$this->currentUser = $previous_user;
	}

	public function createGroup($group) {
		$previous_user = $this->currentUser;
		$this->currentUser = 'admin';
		$this->creatingTheGroup($group);
		$this->groupExists($group);
		$this->currentUser = $previous_user;
	}

	public function deleteGroup($group) {
		$previous_user = $this->currentUser;
		$this->currentUser = 'admin';
		$this->deletingTheGroup($group);
		$this->groupDoesNotExist($group);
		$this->currentUser = $previous_user;
	}

	public function userExists($user) {
		$fullUrl = $this->baseUrl . "v2.php/cloud/users/$user";
		$client = new Client();
		$options = [];
		$options['auth'] = $this->adminUser;
		$options['headers'] = [
			'OCS-APIREQUEST' => 'true'
		];

		$this->response = $client->get($fullUrl, $options);
	}

	/**
	 * @Then /^check that user "([^"]*)" belongs to group "([^"]*)"$/
	 * @param string $user
	 * @param string $group
	 */
	public function checkThatUserBelongsToGroup($user, $group) {
		$fullUrl = $this->baseUrl . "v2.php/cloud/users/$user/groups";
		$client = new Client();
		$options = [];
		if ($this->currentUser === 'admin') {
			$options['auth'] = $this->adminUser;
		}
		$options['headers'] = [
			'OCS-APIREQUEST' => 'true',
		];

		$this->response = $client->get($fullUrl, $options);
		$respondedArray = $this->getArrayOfGroupsResponded($this->response);
		sort($respondedArray);
		Assert::assertContains($group, $respondedArray);
		Assert::assertEquals(200, $this->response->getStatusCode());
	}

	public function userBelongsToGroup($user, $group) {
		$fullUrl = $this->baseUrl . "v2.php/cloud/users/$user/groups";
		$client = new Client();
		$options = [];
		if ($this->currentUser === 'admin') {
			$options['auth'] = $this->adminUser;
		}
		$options['headers'] = [
			'OCS-APIREQUEST' => 'true',
		];

		$this->response = $client->get($fullUrl, $options);
		$respondedArray = $this->getArrayOfGroupsResponded($this->response);

		if (array_key_exists($group, $respondedArray)) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * @Given /^user "([^"]*)" belongs to group "([^"]*)"$/
	 * @param string $user
	 * @param string $group
	 */
	public function assureUserBelongsToGroup($user, $group) {
		$previous_user = $this->currentUser;
		$this->currentUser = 'admin';

		if (!$this->userBelongsToGroup($user, $group)) {
			$this->addingUserToGroup($user, $group);
		}

		$this->checkThatUserBelongsToGroup($user, $group);
		$this->currentUser = $previous_user;
	}

	/**
	 * @Given /^user "([^"]*)" does not belong to group "([^"]*)"$/
	 * @param string $user
	 * @param string $group
	 */
	public function userDoesNotBelongToGroup($user, $group) {
		$fullUrl = $this->baseUrl . "v2.php/cloud/users/$user/groups";
		$client = new Client();
		$options = [];
		if ($this->currentUser === 'admin') {
			$options['auth'] = $this->adminUser;
		}
		$options['headers'] = [
			'OCS-APIREQUEST' => 'true',
		];

		$this->response = $client->get($fullUrl, $options);
		$groups = [$group];
		$respondedArray = $this->getArrayOfGroupsResponded($this->response);
		Assert::assertNotEqualsCanonicalizing($groups, $respondedArray);
		Assert::assertEquals(200, $this->response->getStatusCode());
	}

	/**
	 * @When /^creating the group "([^"]*)"$/
	 * @param string $group
	 */
	public function creatingTheGroup($group) {
		$fullUrl = $this->baseUrl . "v{$this->apiVersion}.php/cloud/groups";
		$client = new Client();
		$options = [];
		if ($this->currentUser === 'admin') {
			$options['auth'] = $this->adminUser;
		}

		$options['form_params'] = [
			'groupid' => $group,
		];
		$options['headers'] = [
			'OCS-APIREQUEST' => 'true',
		];

		$this->response = $client->post($fullUrl, $options);
		if ($this->currentServer === 'LOCAL') {
			$this->createdGroups[$group] = $group;
		} elseif ($this->currentServer === 'REMOTE') {
			$this->createdRemoteGroups[$group] = $group;
		}
	}

	/**
	 * @When /^assure user "([^"]*)" is disabled$/
	 */
	public function assureUserIsDisabled($user) {
		$fullUrl = $this->baseUrl . "v{$this->apiVersion}.php/cloud/users/$user/disable";
		$client = new Client();
		$options = [];
		if ($this->currentUser === 'admin') {
			$options['auth'] = $this->adminUser;
		}
		$options['headers'] = [
			'OCS-APIREQUEST' => 'true',
		];
		// TODO: fix hack
		$options['form_params'] = [
			'foo' => 'bar'
		];

		$this->response = $client->put($fullUrl, $options);
	}

	/**
	 * @When /^Deleting the user "([^"]*)"$/
	 * @param string $user
	 */
	public function deletingTheUser($user) {
		$fullUrl = $this->baseUrl . "v{$this->apiVersion}.php/cloud/users/$user";
		$client = new Client();
		$options = [];
		if ($this->currentUser === 'admin') {
			$options['auth'] = $this->adminUser;
		}
		$options['headers'] = [
			'OCS-APIREQUEST' => 'true',
		];

		$this->response = $client->delete($fullUrl, $options);
	}

	/**
	 * @When /^Deleting the group "([^"]*)"$/
	 * @param string $group
	 */
	public function deletingTheGroup($group) {
		$fullUrl = $this->baseUrl . "v{$this->apiVersion}.php/cloud/groups/$group";
		$client = new Client();
		$options = [];
		if ($this->currentUser === 'admin') {
			$options['auth'] = $this->adminUser;
		}
		$options['headers'] = [
			'OCS-APIREQUEST' => 'true',
		];

		$this->response = $client->delete($fullUrl, $options);

		if ($this->currentServer === 'LOCAL') {
			unset($this->createdGroups[$group]);
		} elseif ($this->currentServer === 'REMOTE') {
			unset($this->createdRemoteGroups[$group]);
		}
	}

	/**
	 * @Given /^Add user "([^"]*)" to the group "([^"]*)"$/
	 * @param string $user
	 * @param string $group
	 */
	public function addUserToGroup($user, $group) {
		$this->userExists($user);
		$this->groupExists($group);
		$this->addingUserToGroup($user, $group);
	}

	/**
	 * @When /^User "([^"]*)" is added to the group "([^"]*)"$/
	 * @param string $user
	 * @param string $group
	 */
	public function addingUserToGroup($user, $group) {
		$fullUrl = $this->baseUrl . "v{$this->apiVersion}.php/cloud/users/$user/groups";
		$client = new Client();
		$options = [];
		if ($this->currentUser === 'admin') {
			$options['auth'] = $this->adminUser;
		}
		$options['headers'] = [
			'OCS-APIREQUEST' => 'true',
		];

		$options['form_params'] = [
			'groupid' => $group,
		];

		$this->response = $client->post($fullUrl, $options);
	}


	public function groupExists($group) {
		$fullUrl = $this->baseUrl . "v2.php/cloud/groups/$group";
		$client = new Client();
		$options = [];
		$options['auth'] = $this->adminUser;
		$options['headers'] = [
			'OCS-APIREQUEST' => 'true',
		];

		$this->response = $client->get($fullUrl, $options);
	}

	/**
	 * @Given /^group "([^"]*)" exists$/
	 * @param string $group
	 */
	public function assureGroupExists($group) {
		try {
			$this->groupExists($group);
		} catch (\GuzzleHttp\Exception\ClientException $ex) {
			$previous_user = $this->currentUser;
			$this->currentUser = 'admin';
			$this->creatingTheGroup($group);
			$this->currentUser = $previous_user;
		}
		$this->groupExists($group);
		Assert::assertEquals(200, $this->response->getStatusCode());
	}

	/**
	 * @Given /^group "([^"]*)" does not exist$/
	 * @param string $group
	 */
	public function groupDoesNotExist($group) {
		try {
			$this->groupExists($group);
		} catch (\GuzzleHttp\Exception\ClientException $ex) {
			$this->response = $ex->getResponse();
			Assert::assertEquals(404, $ex->getResponse()->getStatusCode());
			return;
		}
		$previous_user = $this->currentUser;
		$this->currentUser = 'admin';
		$this->deletingTheGroup($group);
		$this->currentUser = $previous_user;
		try {
			$this->groupExists($group);
		} catch (\GuzzleHttp\Exception\ClientException $ex) {
			$this->response = $ex->getResponse();
			Assert::assertEquals(404, $ex->getResponse()->getStatusCode());
		}
	}

	/**
	 * @Given /^user "([^"]*)" is subadmin of group "([^"]*)"$/
	 * @param string $user
	 * @param string $group
	 */
	public function userIsSubadminOfGroup($user, $group) {
		$fullUrl = $this->baseUrl . "v2.php/cloud/groups/$group/subadmins";
		$client = new Client();
		$options = [];
		if ($this->currentUser === 'admin') {
			$options['auth'] = $this->adminUser;
		}
		$options['headers'] = [
			'OCS-APIREQUEST' => 'true',
		];

		$this->response = $client->get($fullUrl, $options);
		$respondedArray = $this->getArrayOfSubadminsResponded($this->response);
		sort($respondedArray);
		Assert::assertContains($user, $respondedArray);
		Assert::assertEquals(200, $this->response->getStatusCode());
	}

	/**
	 * @Given /^Assure user "([^"]*)" is subadmin of group "([^"]*)"$/
	 * @param string $user
	 * @param string $group
	 */
	public function assureUserIsSubadminOfGroup($user, $group) {
		$fullUrl = $this->baseUrl . "v{$this->apiVersion}.php/cloud/users/$user/subadmins";
		$client = new Client();
		$options = [];
		if ($this->currentUser === 'admin') {
			$options['auth'] = $this->adminUser;
		}
		$options['form_params'] = [
			'groupid' => $group
		];
		$options['headers'] = [
			'OCS-APIREQUEST' => 'true',
		];
		$this->response = $client->post($fullUrl, $options);
		Assert::assertEquals(200, $this->response->getStatusCode());
	}

	/**
	 * @Given /^user "([^"]*)" is not a subadmin of group "([^"]*)"$/
	 * @param string $user
	 * @param string $group
	 */
	public function userIsNotSubadminOfGroup($user, $group) {
		$fullUrl = $this->baseUrl . "v2.php/cloud/groups/$group/subadmins";
		$client = new Client();
		$options = [];
		if ($this->currentUser === 'admin') {
			$options['auth'] = $this->adminUser;
		}
		$options['headers'] = [
			'OCS-APIREQUEST' => 'true',
		];

		$this->response = $client->get($fullUrl, $options);
		$respondedArray = $this->getArrayOfSubadminsResponded($this->response);
		sort($respondedArray);
		Assert::assertNotContains($user, $respondedArray);
		Assert::assertEquals(200, $this->response->getStatusCode());
	}

	/**
	 * @Then /^users returned are$/
	 * @param TableNode|null $usersList
	 */
	public function theUsersShouldBe($usersList) {
		if ($usersList instanceof TableNode) {
			$users = $usersList->getRows();
			$usersSimplified = $this->simplifyArray($users);
			$respondedArray = $this->getArrayOfUsersResponded($this->response);
			Assert::assertEqualsCanonicalizing($usersSimplified, $respondedArray);
		}
	}

	/**
	 * @Then /^phone matches returned are$/
	 * @param TableNode|null $usersList
	 */
	public function thePhoneUsersShouldBe($usersList) {
		if ($usersList instanceof TableNode) {
			$users = $usersList->getRowsHash();
			$listCheckedElements = simplexml_load_string($this->response->getBody())->data;
			$respondedArray = json_decode(json_encode($listCheckedElements), true);
			Assert::assertEquals($users, $respondedArray);
		}
	}

	/**
	 * @Then /^detailed users returned are$/
	 * @param TableNode|null $usersList
	 */
	public function theDetailedUsersShouldBe($usersList) {
		if ($usersList instanceof TableNode) {
			$users = $usersList->getRows();
			$usersSimplified = $this->simplifyArray($users);
			$respondedArray = $this->getArrayOfDetailedUsersResponded($this->response);
			$respondedArray = array_keys($respondedArray);
			Assert::assertEquals($usersSimplified, $respondedArray);
		}
	}

	/**
	 * @Then /^groups returned are$/
	 * @param TableNode|null $groupsList
	 */
	public function theGroupsShouldBe($groupsList) {
		if ($groupsList instanceof TableNode) {
			$groups = $groupsList->getRows();
			$groupsSimplified = $this->simplifyArray($groups);
			$respondedArray = $this->getArrayOfGroupsResponded($this->response);
			Assert::assertEqualsCanonicalizing($groupsSimplified, $respondedArray);
		}
	}

	/**
	 * @Then /^subadmin groups returned are$/
	 * @param TableNode|null $groupsList
	 */
	public function theSubadminGroupsShouldBe($groupsList) {
		if ($groupsList instanceof TableNode) {
			$groups = $groupsList->getRows();
			$groupsSimplified = $this->simplifyArray($groups);
			$respondedArray = $this->getArrayOfSubadminsResponded($this->response);
			Assert::assertEqualsCanonicalizing($groupsSimplified, $respondedArray);
		}
	}

	/**
	 * @Then /^apps returned are$/
	 * @param TableNode|null $appList
	 */
	public function theAppsShouldBe($appList) {
		if ($appList instanceof TableNode) {
			$apps = $appList->getRows();
			$appsSimplified = $this->simplifyArray($apps);
			$respondedArray = $this->getArrayOfAppsResponded($this->response);
			Assert::assertEqualsCanonicalizing($appsSimplified, $respondedArray);
		}
	}

	/**
	 * @Then /^subadmin users returned are$/
	 * @param TableNode|null $groupsList
	 */
	public function theSubadminUsersShouldBe($groupsList) {
		$this->theSubadminGroupsShouldBe($groupsList);
	}

	/**
	 * Parses the xml answer to get the array of users returned.
	 *
	 * @param ResponseInterface $resp
	 * @return array
	 */
	public function getArrayOfUsersResponded($resp) {
		$listCheckedElements = simplexml_load_string($resp->getBody())->data[0]->users[0]->element;
		$extractedElementsArray = json_decode(json_encode($listCheckedElements), 1);
		return $extractedElementsArray;
	}

	/**
	 * Parses the xml answer to get the array of detailed users returned.
	 *
	 * @param ResponseInterface $resp
	 * @return array
	 */
	public function getArrayOfDetailedUsersResponded($resp) {
		$listCheckedElements = simplexml_load_string($resp->getBody())->data[0]->users;
		$extractedElementsArray = json_decode(json_encode($listCheckedElements), 1);
		return $extractedElementsArray;
	}

	/**
	 * Parses the xml answer to get the array of groups returned.
	 *
	 * @param ResponseInterface $resp
	 * @return array
	 */
	public function getArrayOfGroupsResponded($resp) {
		$listCheckedElements = simplexml_load_string($resp->getBody())->data[0]->groups[0]->element;
		$extractedElementsArray = json_decode(json_encode($listCheckedElements), 1);
		return $extractedElementsArray;
	}

	/**
	 * Parses the xml answer to get the array of apps returned.
	 *
	 * @param ResponseInterface $resp
	 * @return array
	 */
	public function getArrayOfAppsResponded($resp) {
		$listCheckedElements = simplexml_load_string($resp->getBody())->data[0]->apps[0]->element;
		$extractedElementsArray = json_decode(json_encode($listCheckedElements), 1);
		return $extractedElementsArray;
	}

	/**
	 * Parses the xml answer to get the array of subadmins returned.
	 *
	 * @param ResponseInterface $resp
	 * @return array
	 */
	public function getArrayOfSubadminsResponded($resp) {
		$listCheckedElements = simplexml_load_string($resp->getBody())->data[0]->element;
		$extractedElementsArray = json_decode(json_encode($listCheckedElements), 1);
		return $extractedElementsArray;
	}


	/**
	 * @Given /^app "([^"]*)" is disabled$/
	 * @param string $app
	 */
	public function appIsDisabled($app) {
		$fullUrl = $this->baseUrl . 'v2.php/cloud/apps?filter=disabled';
		$client = new Client();
		$options = [];
		if ($this->currentUser === 'admin') {
			$options['auth'] = $this->adminUser;
		}
		$options['headers'] = [
			'OCS-APIREQUEST' => 'true',
		];

		$this->response = $client->get($fullUrl, $options);
		$respondedArray = $this->getArrayOfAppsResponded($this->response);
		Assert::assertContains($app, $respondedArray);
		Assert::assertEquals(200, $this->response->getStatusCode());
	}

	/**
	 * @Given /^app "([^"]*)" is enabled$/
	 * @param string $app
	 */
	public function appIsEnabled($app) {
		$fullUrl = $this->baseUrl . 'v2.php/cloud/apps?filter=enabled';
		$client = new Client();
		$options = [];
		if ($this->currentUser === 'admin') {
			$options['auth'] = $this->adminUser;
		}
		$options['headers'] = [
			'OCS-APIREQUEST' => 'true',
		];

		$this->response = $client->get($fullUrl, $options);
		$respondedArray = $this->getArrayOfAppsResponded($this->response);
		Assert::assertContains($app, $respondedArray);
		Assert::assertEquals(200, $this->response->getStatusCode());
	}

	/**
	 * @Given /^app "([^"]*)" is not enabled$/
	 *
	 * Checks that the app is disabled or not installed.
	 *
	 * @param string $app
	 */
	public function appIsNotEnabled($app) {
		$fullUrl = $this->baseUrl . 'v2.php/cloud/apps?filter=enabled';
		$client = new Client();
		$options = [];
		if ($this->currentUser === 'admin') {
			$options['auth'] = $this->adminUser;
		}
		$options['headers'] = [
			'OCS-APIREQUEST' => 'true',
		];

		$this->response = $client->get($fullUrl, $options);
		$respondedArray = $this->getArrayOfAppsResponded($this->response);
		Assert::assertNotContains($app, $respondedArray);
		Assert::assertEquals(200, $this->response->getStatusCode());
	}

	/**
	 * @Then /^user "([^"]*)" is disabled$/
	 * @param string $user
	 */
	public function userIsDisabled($user) {
		$fullUrl = $this->baseUrl . "v{$this->apiVersion}.php/cloud/users/$user";
		$client = new Client();
		$options = [];
		if ($this->currentUser === 'admin') {
			$options['auth'] = $this->adminUser;
		}
		$options['headers'] = [
			'OCS-APIREQUEST' => 'true',
		];

		$this->response = $client->get($fullUrl, $options);
		// false in xml is empty
		Assert::assertTrue(empty(simplexml_load_string($this->response->getBody())->data[0]->enabled));
	}

	/**
	 * @Then /^user "([^"]*)" is enabled$/
	 * @param string $user
	 */
	public function userIsEnabled($user) {
		$fullUrl = $this->baseUrl . "v{$this->apiVersion}.php/cloud/users/$user";
		$client = new Client();
		$options = [];
		if ($this->currentUser === 'admin') {
			$options['auth'] = $this->adminUser;
		}
		$options['headers'] = [
			'OCS-APIREQUEST' => 'true',
		];

		$this->response = $client->get($fullUrl, $options);
		// boolean to string is integer
		Assert::assertEquals('1', simplexml_load_string($this->response->getBody())->data[0]->enabled);
	}

	/**
	 * @Given user :user has a quota of :quota
	 * @param string $user
	 * @param string $quota
	 */
	public function userHasAQuotaOf($user, $quota) {
		$body = new TableNode([
			0 => ['key', 'quota'],
			1 => ['value', $quota],
		]);

		// method used from BasicStructure trait
		$this->sendingToWith('PUT', '/cloud/users/' . $user, $body);
	}

	/**
	 * @Given user :user has unlimited quota
	 * @param string $user
	 */
	public function userHasUnlimitedQuota($user) {
		$this->userHasAQuotaOf($user, 'none');
	}

	/**
	 * Returns home path of the given user
	 *
	 * @param string $user
	 */
	public function getUserHome($user) {
		$fullUrl = $this->baseUrl . "v{$this->apiVersion}.php/cloud/users/$user";
		$client = new Client();
		$options = [];
		$options['auth'] = $this->adminUser;
		$this->response = $client->get($fullUrl, $options);
		return simplexml_load_string($this->response->getBody())->data[0]->home;
	}

	/**
	 * @BeforeScenario
	 * @AfterScenario
	 */
	public function cleanupUsers() {
		$previousServer = $this->currentServer;
		$this->usingServer('LOCAL');
		foreach ($this->createdUsers as $user) {
			$this->deleteUser($user);
		}
		$this->usingServer('REMOTE');
		foreach ($this->createdRemoteUsers as $remoteUser) {
			$this->deleteUser($remoteUser);
		}
		$this->usingServer($previousServer);
	}

	/**
	 * @BeforeScenario
	 * @AfterScenario
	 */
	public function cleanupGroups() {
		$previousServer = $this->currentServer;
		$this->usingServer('LOCAL');
		foreach ($this->createdGroups as $group) {
			$this->deleteGroup($group);
		}
		$this->usingServer('REMOTE');
		foreach ($this->createdRemoteGroups as $remoteGroup) {
			$this->deleteGroup($remoteGroup);
		}
		$this->usingServer($previousServer);
	}

	/**
	 * @Then /^user "([^"]*)" has not$/
	 */
	public function userHasNotSetting($user, TableNode $settings) {
		$fullUrl = $this->baseUrl . "v{$this->apiVersion}.php/cloud/users/$user";
		$client = new Client();
		$options = [];
		if ($this->currentUser === 'admin') {
			$options['auth'] = $this->adminUser;
		} else {
			$options['auth'] = [$this->currentUser, $this->regularUser];
		}
		$options['headers'] = [
			'OCS-APIREQUEST' => 'true',
		];

		$response = $client->get($fullUrl, $options);
		foreach ($settings->getRows() as $setting) {
			$value = json_decode(json_encode(simplexml_load_string($response->getBody())->data->{$setting[0]}), 1);
			if (isset($value[0])) {
				if (in_array($setting[0], ['additional_mail', 'additional_mailScope'], true)) {
					$expectedValues = explode(';', $setting[1]);
					foreach ($expectedValues as $expected) {
						Assert::assertFalse(in_array($expected, $value, true));
					}
				} else {
					Assert::assertNotEqualsCanonicalizing($setting[1], $value[0]);
				}
			} else {
				Assert::assertNotEquals('', $setting[1]);
			}
		}
	}
}
