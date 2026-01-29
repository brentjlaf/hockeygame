<?php
declare(strict_types=1);

final class Division {
  public static function list(): array {
    return [
      [
        'key' => 'bronze',
        'name' => 'Bronze',
        'min_rating' => 0,
        'max_rating' => 899,
      ],
      [
        'key' => 'silver',
        'name' => 'Silver',
        'min_rating' => 900,
        'max_rating' => 1049,
      ],
      [
        'key' => 'gold',
        'name' => 'Gold',
        'min_rating' => 1050,
        'max_rating' => 1199,
      ],
      [
        'key' => 'elite',
        'name' => 'Elite',
        'min_rating' => 1200,
        'max_rating' => 9999,
      ],
    ];
  }

  public static function fromRating(int $rating): array {
    foreach (self::list() as $division) {
      if ($rating >= $division['min_rating'] && $rating <= $division['max_rating']) {
        return $division;
      }
    }

    return [
      'key' => 'bronze',
      'name' => 'Bronze',
      'min_rating' => 0,
      'max_rating' => 899,
    ];
  }
}
