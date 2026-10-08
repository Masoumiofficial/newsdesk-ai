<?php
/**
 * Immutable, versioned prompt (§24) — admin edits create NEW versions, never in-place.
 *
 * @package NewsDesk\AI\Domain\Entity
 */

namespace NewsDesk\AI\Domain\Entity;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Support\Time;

final class PromptVersion extends AbstractEntity {

	/** @var int */
	public $id = 0;
	/** @var string */
	public $promptId = '';
	/** @var int */
	public $version = 1;
	/** @var string */
	public $type = 'research';
	/** @var string */
	public $language = 'fa';
	/** @var string */
	public $content = '';
	/** @var string */
	public $providerTestedOn = '';
	/** @var string */
	public $modelTestedOn = '';
	/** @var bool */
	public $active = false;
	/** @var \DateTimeImmutable|null */
	public $createdAt;
	/** @var \DateTimeImmutable|null */
	public $updatedAt;

	public static function fromDbRow( array $row ): self {
		$p                    = new self();
		$p->id                = self::intOr( $row['id'] ?? null, 0 );
		$p->promptId          = self::strOr( $row['prompt_id'] ?? null );
		$p->version           = self::intOr( $row['version'] ?? null, 1 );
		$p->type              = self::strOr( $row['type'] ?? null, 'research' );
		$p->language          = self::strOr( $row['language'] ?? null, 'fa' );
		$p->content           = self::strOr( $row['content'] ?? null );
		$p->providerTestedOn  = self::strOr( $row['provider_tested_on'] ?? null );
		$p->modelTestedOn     = self::strOr( $row['model_tested_on'] ?? null );
		$p->active            = '1' === (string) ( $row['active'] ?? '0' );
		$p->createdAt         = Time::fromDb( $row['created_at'] ?? null );
		$p->updatedAt         = Time::fromDb( $row['updated_at'] ?? null );
		return $p;
	}

	public function toDbRow(): array {
		return array(
			'prompt_id'          => $this->promptId,
			'version'            => $this->version,
			'type'               => $this->type,
			'language'           => $this->language,
			'content'            => $this->content,
			'provider_tested_on' => $this->providerTestedOn,
			'model_tested_on'    => $this->modelTestedOn,
			'active'             => $this->active ? 1 : 0,
			'created_at'         => Time::toDb( $this->createdAt ),
			'updated_at'         => Time::toDb( $this->updatedAt ),
		);
	}
}
