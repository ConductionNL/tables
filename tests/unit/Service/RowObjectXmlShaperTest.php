<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Tables\Tests\Unit\Service;

use OC\AppFramework\OCS\V1Response;
use OC\AppFramework\OCS\V2Response;
use OCA\Tables\Service\RowObjectXmlShaper;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use PHPUnit\Framework\TestCase;

class RowObjectXmlShaperTest extends TestCase {
	private const UUID = '0199e3a0-1f2b-7c3d-8e4f-5a6b7c8d9e0f';
	private const NIL = ['@attributes' => ['nil' => 'true']];

	private RowObjectXmlShaper $shaper;

	protected function setUp(): void {
		$this->shaper = new RowObjectXmlShaper();
	}

	/**
	 * @return array{'@self': array{id: ?string, rowId: int, tableId: int, createdBy: ?string, createdAt: ?string, updatedBy: ?string, updatedAt: ?string}}
	 */
	private function self(?string $uuid = self::UUID): array {
		return ['@self' => [
			'id' => $uuid,
			'rowId' => 7,
			'tableId' => 3,
			'createdBy' => 'alice',
			'createdAt' => '2026-10-16T14:30:00+00:00',
			'updatedBy' => null,
			'updatedAt' => null,
		]];
	}

	public function testSelfBecomesAnElementWithAnAttributePerMember(): void {
		$this->assertSame(
			['self' => ['@attributes' => [
				'id' => self::UUID,
				'rowId' => '7',
				'tableId' => '3',
				'createdBy' => 'alice',
				'createdAt' => '2026-10-16T14:30:00+00:00',
			]]],
			$this->shaper->shapeRow($this->self()),
		);
	}

	public function testANullSelfMemberIsLeftOut(): void {
		$attributes = $this->shaper->shapeRow($this->self(null))['self']['@attributes'];

		$this->assertArrayNotHasKey('id', $attributes);
		$this->assertArrayNotHasKey('updatedBy', $attributes);
		$this->assertArrayNotHasKey('updatedAt', $attributes);
		$this->assertSame('7', $attributes['rowId']);
	}

	public function testScalarsBecomeText(): void {
		$shaped = $this->shaper->shapeRow($this->self() + [
			'name' => 'Alice',
			'age' => 42,
			'height' => 1.75,
			'whole' => 2.0,
			'done' => true,
			'open' => false,
		]);

		$this->assertSame('Alice', $shaped['name']);
		$this->assertSame('42', $shaped['age']);
		$this->assertSame('1.75', $shaped['height']);
		$this->assertSame(json_encode(2.0), $shaped['whole']);
		$this->assertSame('true', $shaped['done']);
		$this->assertSame('false', $shaped['open']);
	}

	public function testNullIsNilAndAnEmptyStringIsNot(): void {
		$shaped = $this->shaper->shapeRow($this->self() + ['missing' => null, 'blank' => '']);

		$this->assertSame(self::NIL, $shaped['missing']);
		$this->assertSame('', $shaped['blank']);
	}

	public function testAListHasAnElementPerItemAndAnEmptyListIsNotNil(): void {
		$shaped = $this->shaper->shapeRow($this->self() + ['options' => [2, 5], 'tags' => [], 'mixed' => [true, null]]);

		$this->assertSame(['2', '5'], $shaped['options']);
		$this->assertSame([], $shaped['tags']);
		$this->assertSame(['true', self::NIL], $shaped['mixed']);
	}

	public function testAnObjectHasAChildElementPerPropertyByTheSameRules(): void {
		$shaped = $this->shaper->shapeRow($this->self() + [
			'website' => ['title' => 'Nextcloud', 'url' => 'https://nextcloud.com', 'providerId' => 'url'],
			'people' => [
				['id' => 'alice', 'type' => 0, 'displayName' => 'Alice'],
				['id' => 'team', 'type' => 1, 'displayName' => null],
			],
		]);

		$this->assertSame(['title' => 'Nextcloud', 'url' => 'https://nextcloud.com', 'providerId' => 'url'], $shaped['website']);
		$this->assertSame([
			['id' => 'alice', 'type' => '0', 'displayName' => 'Alice'],
			['id' => 'team', 'type' => '1', 'displayName' => self::NIL],
		], $shaped['people']);
	}

	/**
	 * @return array<string, array{int|string}>
	 */
	public static function unsafeNames(): array {
		return [
			'a number' => [12],
			'a leading digit' => ['1st'],
			'a space' => ['first name'],
			'a colon' => ['a:b'],
			'an at sign, which OCS writes as an attribute' => ['@attributes'],
			'a dollar sign, which OCS writes as a comment' => ['$comment'],
			'the name of a list item' => ['element'],
			'the name of the metadata' => ['self'],
			'a trailing newline' => ["name\n"],
			'a non-ASCII letter' => ['größe'],
		];
	}

	/**
	 * @dataProvider unsafeNames
	 */
	public function testAnUnsafeNameFallsBackToANamedElement(int|string $name): void {
		$shaped = $this->shaper->shapeRow($this->self() + [$name => 'Alice', 'next' => 'Bob']);

		$this->assertSame([
			'self',
			0,
			'next',
		], array_keys($shaped));
		$this->assertSame(['@attributes' => ['name' => (string)$name], 'value' => 'Alice'], $shaped[0]);
	}

	public function testTheFallbackHoldsNilListsAndObjectsByTheSameRules(): void {
		$shaped = $this->shaper->shapeRow($this->self() + [
			'1' => null,
			'2' => [4, 5],
			'3' => ['title' => 'Nextcloud', '4' => true],
		]);

		$this->assertSame(['@attributes' => ['name' => '1'], 'value' => self::NIL], $shaped[0]);
		$this->assertSame(['@attributes' => ['name' => '2'], '4', '5'], $shaped[1]);
		$this->assertSame(['@attributes' => ['name' => '3'], 'title' => 'Nextcloud', ['@attributes' => ['name' => '4'], 'value' => 'true']], $shaped[2]);
	}

	public function testShapeRowsShapesEachRow(): void {
		$this->assertSame(
			[['self' => ['@attributes' => ['id' => 'a', 'rowId' => '1']], 'name' => 'Alice'], ['self' => ['@attributes' => ['rowId' => '2']], 'name' => self::NIL]],
			$this->shaper->shapeRows([
				['@self' => ['id' => 'a', 'rowId' => 1], 'name' => 'Alice'],
				['@self' => ['id' => null, 'rowId' => 2], 'name' => null],
			]),
		);
	}

	public function testAValidationErrorHasAnElementPerName(): void {
		$this->assertSame(
			['message' => 'The row has 3 invalid values.', 'errors' => [
				'age' => 'Expected a number.',
				['@attributes' => ['name' => 'first name'], 'value' => 'There is no column with this technical name.'],
				['@attributes' => ['name' => '@self'], 'value' => 'There is no column with this technical name.'],
			]],
			$this->shaper->shapeValidationError(['message' => 'The row has 3 invalid values.', 'errors' => [
				'age' => 'Expected a number.',
				'first name' => 'There is no column with this technical name.',
				'@self' => 'There is no column with this technical name.',
			]]),
		);
	}

	private function renderXml(mixed $data, int $status = Http::STATUS_OK, bool $v1 = false): \SimpleXMLElement {
		$response = new DataResponse($data, $status);
		$xml = ($v1 ? new V1Response($response, 'xml') : new V2Response($response, 'xml'))->render();
		$document = simplexml_load_string($xml);
		$this->assertNotFalse($document, $xml);
		return $document;
	}

	public function testAShapedPageRendersAsOcsXml(): void {
		$object = $this->self() + [
			'name' => 'Alice',
			'options' => [2, 5],
			'tags' => [],
			'website' => ['title' => 'Nextcloud', 'url' => 'https://nextcloud.com/?a=1&b=<2>', 'providerId' => 'url'],
			'starts' => null,
			'blank' => '',
			'done' => false,
			'12' => 'imported',
		];

		foreach ([false, true] as $v1) {
			$document = $this->renderXml(['results' => $this->shaper->shapeRows([$object]), 'total' => 1], v1: $v1);

			$this->assertSame('1', (string)$document->data->total);
			$row = $document->data->results->element[0];
			$this->assertCount(1, $document->data->results->element);
			$this->assertSame(self::UUID, (string)$row->self['id']);
			$this->assertSame('7', (string)$row->self['rowId']);
			$this->assertSame('alice', (string)$row->self['createdBy']);
			$this->assertNull($row->self['updatedBy']);
			$this->assertSame('', (string)$row->self);
			$this->assertSame('Alice', (string)$row->name);
			$this->assertSame(['2', '5'], array_map(strval(...), iterator_to_array($row->options->element, false)));
			$this->assertCount(0, $row->tags->children());
			$this->assertNull($row->tags['nil']);
			$this->assertSame('https://nextcloud.com/?a=1&b=<2>', (string)$row->website->url);
			$this->assertSame('Nextcloud', (string)$row->website->title);
			$this->assertSame('true', (string)$row->starts['nil']);
			$this->assertSame('', (string)$row->starts);
			$this->assertNull($row->blank['nil']);
			$this->assertSame('', (string)$row->blank);
			$this->assertSame('false', (string)$row->done);
			$this->assertSame('12', (string)$row->element['name']);
			$this->assertSame('imported', (string)$row->element->value);
		}
	}

	/**
	 * XMLWriter refuses some names that Unicode counts as letters, so only ASCII names
	 * become elements.
	 */
	public function testNonAsciiNamesRenderAsNamedElements(): void {
		$names = ['µ', 'ǅ', 'ᐁ', '𝒜', 'größe'];
		$object = $this->self();
		$errors = [];
		foreach ($names as $name) {
			$object[$name] = 'value of ' . $name;
			$errors[$name] = 'There is no column with this technical name.';
		}

		foreach ([false, true] as $v1) {
			$row = $this->renderXml($this->shaper->shapeRow($object), v1: $v1)->data;
			$error = $this->renderXml(
				$this->shaper->shapeValidationError(['message' => 'The row has 5 invalid values.', 'errors' => $errors]),
				Http::STATUS_BAD_REQUEST,
				$v1,
			)->data->errors;

			$this->assertCount(count($names), $row->element);
			$this->assertCount(count($names), $error->element);
			foreach ($names as $i => $name) {
				$this->assertSame($name, (string)$row->element[$i]['name']);
				$this->assertSame('value of ' . $name, (string)$row->element[$i]->value);
				$this->assertSame($name, (string)$error->element[$i]['name']);
				$this->assertSame('There is no column with this technical name.', (string)$error->element[$i]->value);
			}
		}
	}

	/**
	 * XMLWriter writes characters XML does not allow as they are, so the shaper replaces
	 * them and the document stays well-formed.
	 */
	public function testCharactersXmlDoesNotAllowBecomeReplacementCharacters(): void {
		$object = $this->self();
		$object['@self']['createdBy'] = "al\x01ice";
		$object["a\x01"] = "control\x01";
		$object["\xff"] = "invalid\xff";
		$errors = ["a\x01" => 'There is no column with this technical name.', "\xff" => 'There is no column with this technical name.'];

		foreach ([false, true] as $v1) {
			foreach ([
				[$this->shaper->shapeRow($object), Http::STATUS_OK],
				[$this->shaper->shapeValidationError(['message' => 'The row has 2 invalid values.', 'errors' => $errors]), Http::STATUS_BAD_REQUEST],
			] as [$data, $status]) {
				$xml = ($v1 ? new V1Response(new DataResponse($data, $status), 'xml') : new V2Response(new DataResponse($data, $status), 'xml'))->render();
				$document = new \DOMDocument();
				$this->assertTrue($document->loadXML($xml), $xml);

				$names = [];
				foreach ((new \DOMXPath($document))->query('//element[@name]') as $element) {
					$this->assertInstanceOf(\DOMElement::class, $element);
					$names[$element->getAttribute('name')] = $element->getElementsByTagName('value')->item(0)?->textContent;
				}
				$this->assertSame(["a\u{FFFD}", "\u{FFFD}"], array_keys($names));
				if ($status === Http::STATUS_OK) {
					$this->assertSame(["control\u{FFFD}", "invalid\u{FFFD}"], array_values($names));
					$this->assertSame("al\u{FFFD}ice", $document->getElementsByTagName('self')->item(0)?->getAttribute('createdBy'));
				}
			}
		}
	}

	public function testAShapedRowRendersAsOcsXmlAndTheUnshapedOneDoesNot(): void {
		$object = $this->self() + ['name' => 'Alice'];

		$this->assertSame('Alice', (string)$this->renderXml($this->shaper->shapeRow($object))->data->name);

		$this->expectException(\TypeError::class);
		(new V2Response(new DataResponse($object), 'xml'))->render();
	}

	public function testAShapedValidationErrorRendersAsOcsXml(): void {
		$document = $this->renderXml(
			$this->shaper->shapeValidationError(['message' => 'The row has 2 invalid values.', 'errors' => [
				'age' => 'Expected a number.',
				'@self' => 'There is no column with this technical name.',
			]]),
			Http::STATUS_BAD_REQUEST,
		);

		$this->assertSame('400', (string)$document->meta->statuscode);
		$this->assertSame('The row has 2 invalid values.', (string)$document->data->message);
		$this->assertSame('Expected a number.', (string)$document->data->errors->age);
		$this->assertSame('@self', (string)$document->data->errors->element['name']);
		$this->assertSame('There is no column with this technical name.', (string)$document->data->errors->element->value);
	}
}
