<?php

namespace App\Model;

class Venta
{
    public $id;
    public $id_product;
    public $id_sale;
    public $description;
    public $quantity;
    public $amount;
    public $sale_product_date;
    public $type_payment;

    public function __construct($id, $id_product, $id_sale, $description, $quantity, $amount, $sale_product_date, $type_payment)
    {
        $this->id = $id;
        $this->id_product = $id_product;
        $this->id_sale = $id_sale;
        $this->description = $description;
        $this->quantity = $quantity;
        $this->amount = $amount;
        $this->sale_product_date = $sale_product_date;
        $this->type_payment = $type_payment;
    }
}
