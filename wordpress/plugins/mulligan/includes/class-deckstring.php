<?php
if (!defined('ABSPATH')) exit;

/**
 * Hearthstone deckstring decoder.
 * Реализация формата HearthSim/hearthstone-deckstrings (LEB128 varint в base64).
 * Поддерживает версии deckstring v1+ и блоки sideboard (ETC, Zilliax Deluxe 3000).
 */
class HS_Mulligan_Deckstring {

    /**
     * @param string $deckstring Base64-кодированный deckstring.
     * @return array {
     *     format:int,
     *     heroes:int[],
     *     cards:array<int,array{0:int,1:int}>,        // [dbfId, count]
     *     sideboards:array<int,array<int,array{0:int,1:int}>> // ownerDbfId => [[dbfId,count], ...]
     * }
     * @throws Exception
     */
    public static function decode($deckstring) {
        $deckstring = trim((string) $deckstring);
        if ($deckstring === '') throw new Exception('Пустой код');

        $bin = base64_decode($deckstring, true);
        if ($bin === false || strlen($bin) < 3) throw new Exception('Не base64');

        $pos = 0;
        $reserved = ord($bin[$pos++]);
        if ($reserved !== 0) throw new Exception('Неверный заголовок');

        $version = self::read_varint($bin, $pos);
        if ($version < 1) throw new Exception('Неподдерживаемая версия: ' . $version);

        $format = self::read_varint($bin, $pos);

        $num_heroes = self::read_varint($bin, $pos);
        $heroes = array();
        for ($i = 0; $i < $num_heroes; $i++) $heroes[] = self::read_varint($bin, $pos);

        $cards = self::read_card_block($bin, $pos); // singles + doubles + N

        // Sideboards (deckstring v3+, есть в современных колодах с ETC/Zilliax).
        // Структура: opt-byte (1 = есть sideboards) + N-cards-блок с парами (dbf, count, owner_dbf).
        $sideboards = array();
        if ($pos < strlen($bin)) {
            $has_sideboards = ord($bin[$pos++]);
            if ($has_sideboards === 1) {
                // singles
                $num = self::read_varint($bin, $pos);
                for ($i = 0; $i < $num; $i++) {
                    $card_dbf  = self::read_varint($bin, $pos);
                    $owner_dbf = self::read_varint($bin, $pos);
                    $sideboards[$owner_dbf][] = array($card_dbf, 1);
                }
                // doubles
                $num = self::read_varint($bin, $pos);
                for ($i = 0; $i < $num; $i++) {
                    $card_dbf  = self::read_varint($bin, $pos);
                    $owner_dbf = self::read_varint($bin, $pos);
                    $sideboards[$owner_dbf][] = array($card_dbf, 2);
                }
                // N
                $num = self::read_varint($bin, $pos);
                for ($i = 0; $i < $num; $i++) {
                    $card_dbf  = self::read_varint($bin, $pos);
                    $count     = self::read_varint($bin, $pos);
                    $owner_dbf = self::read_varint($bin, $pos);
                    $sideboards[$owner_dbf][] = array($card_dbf, $count);
                }
            }
        }

        return array(
            'format'     => $format,
            'heroes'     => $heroes,
            'cards'      => $cards,
            'sideboards' => $sideboards,
        );
    }

    /**
     * Читает 3-блочную секцию карт (singles, doubles, N) и склеивает в плоский список.
     */
    private static function read_card_block($bin, &$pos) {
        $cards = array();
        $num_singles = self::read_varint($bin, $pos);
        for ($i = 0; $i < $num_singles; $i++) {
            $cards[] = array(self::read_varint($bin, $pos), 1);
        }
        $num_doubles = self::read_varint($bin, $pos);
        for ($i = 0; $i < $num_doubles; $i++) {
            $cards[] = array(self::read_varint($bin, $pos), 2);
        }
        $num_n = self::read_varint($bin, $pos);
        for ($i = 0; $i < $num_n; $i++) {
            $dbf_id = self::read_varint($bin, $pos);
            $count  = self::read_varint($bin, $pos);
            $cards[] = array($dbf_id, $count);
        }
        return $cards;
    }

    /**
     * Чтение unsigned LEB128 varint.
     */
    private static function read_varint($bin, &$pos) {
        $result = 0;
        $shift  = 0;
        $len    = strlen($bin);
        while ($pos < $len) {
            $byte = ord($bin[$pos++]);
            $result |= ($byte & 0x7F) << $shift;
            if (($byte & 0x80) === 0) return $result;
            $shift += 7;
            if ($shift > 63) throw new Exception('Слишком длинный varint');
        }
        throw new Exception('Неожиданный конец данных');
    }
}
