<?php
namespace Ws\FshImport\DataProvider;

use Ttree\ContentRepositoryImporter\DataProvider\DataProvider;
use Ttree\ContentRepositoryImporter\DataType\StringValue;

class GroupContentDataProvider extends DataProvider {
	/**
	 * @return array
	 */
	public function fetch() {
		$result = [];

		$query = $this->createQuery()
		->select('*')
		->from('con_content', 'n');
		$statement = $query->execute();

		while ($record = $statement->fetch()) {
			$value = html_entity_decode(urldecode($record['value']));
			if ($record['idtype'] == 1) {
				$entry = [];
				$entry['_type'] = 'TYPO3.Neos.NodeTypes:Headline';
				$entry['title'] = '<h2>' . strip_tags($value) . '</h2>';
				$nodes = [$entry];
			} else if ($record['idtype'] == 2) {
				$nodes = $this->parseHtml($value);
			}

			$result[] = [
				'__externalIdentifier' => (int)$record['idcontent'],
				'__parentIdentifier' => (int)$record['idartlang'],
				'type' => (int)$record['idtype'],
				'main' => $nodes
			];
		}
		$this->count = count($result);
		return $result;
	}

	protected function domElementToHtml($element) {
		$tempDom = new \DOMDocument();
		$clonedNode = $element->cloneNode(true);
		$tempDom->appendChild($tempDom->importNode($clonedNode, true));
		return html_entity_decode(trim($tempDom->saveHTML()));
	}

	protected function parseHtml($html) {
		if (!$html) {
			return null;
		}

		// Replace b and i tags
		$html = str_replace('<b>', '<strong>', $html);
		$html = str_replace('</b>', '</strong>', $html);
		$html = str_replace('<i>', '<em>', $html);
		$html = str_replace('</i>', '</em>', $html);

		$dom = new \DOMDocument;
		// Ignore warnings
		libxml_use_internal_errors(true);
		// Fix encoding issues
		$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
		libxml_use_internal_errors(false);
		$xpath = new \DomXPath($dom);

		// Remove style from span tags
		$items = $xpath->query("//*[@style]");
		foreach($items as $item) {
			$item->removeAttribute("style");
		}

		// p.headlin -> h3
		$headings = $xpath->query("//p[contains(@class, 'headline')]");
		foreach($headings as $item) {
			$newNode = $dom->createElement('h3', $item->nodeValue);
			$item->parentNode->replaceChild($newNode, $item);
			$item->removeAttribute("style");
		}

		// Iterate over all top-level nodes, and split them into Image nodes and Text nodes
		$pointer = 0;
		$nodes = [];

		// Iterate over all top-level nodes
		foreach ($dom->getElementsByTagName('body')->item(0)->childNodes as $element) {
			// Find all images
			foreach($xpath->query(".//img[starts-with(@src, 'upload/')]", $element) as $img) {
				$pointer++;
				$nodes[$pointer] = array(
					'_type' => 'TYPO3.Neos.NodeTypes:Image',
					'image' => $img->getAttribute('src'),
					'alt' => $img->getAttribute('alt'),
					'floated' => $img->getAttribute('class') === 'bild_links'
				);
				// Remove image tag of newly added image
				$img->parentNode->removeChild($img);
			}
			// Remove empty tags
			while (($nodeList = $xpath->query('//*[not(*) and not(@*) and not(text()[normalize-space()])]')) && $nodeList->length) {
				foreach ($nodeList as $node) {
					$node->parentNode->removeChild($node);
				}
			}
			$text = $this->domElementToHtml($element);
			if ($text) {
				$prevValue = '';
				if (isset($nodes[$pointer])) {
					if ($nodes[$pointer]['_type'] === 'TYPO3.Neos.NodeTypes:Text') {
						$prevValue = $nodes[$pointer]['text'];
					} else {
						$pointer++;
					}
				}
				$nodes[$pointer] = array(
					'_type' => 'TYPO3.Neos.NodeTypes:Text',
					'text' => $prevValue . $text
				);
			}
		}
		return $nodes;
	}
}
