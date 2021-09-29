<?php

namespace App\Gateway;

use Illuminate\Database\ConnectionInterface;

class UserGateway
{
    /**
     * @var ConnectionInterface
     */
    private $db;

    public function __construct()
    {
        $this->db = app('db');
    }


    // Users
    public function getUser(string $userId)
    {
        $user = $this->db->table('users')
            ->where('user_id', $userId)
            ->first();

        if ($user) {
            return (array) $user;
        }

        return null;
    }


    public function saveUser(string $userId, string $displayName)
    {
        $this->db->table('users')
            ->insert([
                'user_id' => $userId,
                'display_name' => $displayName
            ]);
    }

    public function simpanMeja($userId, $noMeja)
    {
        $this->db->table('users')
            ->update([
                'user_id' => $userId,
                'nomeja' => $noMeja,
                'number' => 1
            ]);
    }

    public function simpanMenu($userId, $menu)
    {
        $this->db->table('users')
            ->update([
                'user_id' => $userId,
                'menu' => $menu
            ]);
    }

    public function simpanPorsi($userId, $porsi)
    {
        $this->db->table('users')
            ->update([
                'user_id' => $userId,
                'porsi' => $porsi
            ]);
    }

    public function ambilMenu($paket)
    {
        $harga = $this->db->table('menu')
            ->where('menu', $paket)
            ->first();

        if ($harga) {
            return (array) $harga;
        }
    }

    public function simpanOrder($userId, $displayName, $menu, $meja, $porsi, $jumlahorder)
    {
        $this->db->table('orderlog')->insert([
            'user_id' => $userId,
            'display_name' => $displayName,
            'menu' => $menu,
            'nomeja' => $meja,
            'porsi' => $porsi
        ]);
    }

    public function ulangUsers($userId, $jumlahorder)
    {
        $this->db->table('users')->where('user_id', $userId)->update([
            'menu' => null,
            'nomeja' => null,
            'porsi' => null,
            'number' => 0,
            'jumlah_order' => $jumlahorder
        ]);
    }

    public function ulangOrder($userId)
    {
        $this->db->table('users')->update([
            'user_id' => $userId,
            'menu' => "",
            'porsi' => null
        ]);
    }
}
