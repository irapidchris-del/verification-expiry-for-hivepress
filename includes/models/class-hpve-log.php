<?php
/**
 * Audit trail entry model.
 *
 * A comment of type "hp_hpve_log" (11 characters: comment_type is varchar(20) and the class name
 * has 17 to play with, resources/hivepress-data.md, "A comment model's class name has 17
 * characters"). Comment::save() inserts through wp_insert_comment() and bypasses moderation, so the
 * approved flag is a field of its own, as Reviews does. The author columns are populated so the
 * rows read correctly wherever WordPress lists comments, though includes/configs/comment-types.php
 * marks the type non-public and core keeps it off every comment screen and feed.
 *
 * Rows are only ever written by Hpve_Request::log(); nothing else instantiates this.
 *
 * @package HivePress\Verification_Expiry
 */

namespace HivePress\Models;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * One line of a request's history.
 *
 * @method int|null get_request__id()
 * @method int|null get_user__id()
 * @method string|null get_author()
 * @method string|null get_author_email()
 * @method string|null get_text()
 * @method string|null get_created_date()
 * @method string|null get_action()
 * @method bool|null is_visible()
 * @method self set_request( mixed $value )
 * @method self set_user( mixed $value )
 * @method self set_author( mixed $value )
 * @method self set_author_email( mixed $value )
 * @method self set_text( mixed $value )
 * @method self set_approved( mixed $value )
 * @method self set_action( mixed $value )
 * @method self set_visible( mixed $value )
 */
class Hpve_Log extends Comment {

	/**
	 * Class constructor.
	 *
	 * @param array $args Model arguments.
	 */
	public function __construct( $args = [] ) {
		$args = hp\merge_arrays(
			[
				'fields' => [
					'request'      => [
						'type'     => 'id',
						'required' => true,
						'_alias'   => 'comment_post_ID',
						'_model'   => 'hpve_request',
					],

					'user'         => [
						'type'      => 'number',
						'min_value' => 0,
						'_alias'    => 'user_id',
					],

					'author'       => [
						'type'       => 'text',
						'max_length' => 256,
						'_alias'     => 'comment_author',
					],

					'author_email' => [
						'type'       => 'text',
						'max_length' => 256,
						'_alias'     => 'comment_author_email',
					],

					'text'         => [
						'type'       => 'textarea',
						'max_length' => 2000,
						'html'       => false,
						'required'   => true,
						'_alias'     => 'comment_content',
					],

					'created_date' => [
						'type'   => 'date',
						'format' => 'Y-m-d H:i:s',
						'_alias' => 'comment_date',
					],

					'approved'     => [
						'type'      => 'number',
						'min_value' => 0,
						'max_value' => 1,
						'_alias'    => 'comment_approved',
					],

					'action'       => [
						'type'       => 'text',
						'max_length' => 32,
						'_alias'     => 'hp_hpve_action',
						'_external'  => true,
					],

					'visible'      => [
						'type'      => 'checkbox',
						'_alias'    => 'hp_hpve_visible',
						'_external' => true,
					],
				],
			],
			$args
		);

		parent::__construct( $args );
	}
}
