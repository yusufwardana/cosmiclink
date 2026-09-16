<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    use HasFactory;

    protected $fillable = ['invoice_id', 'customer_connection_id', 'internet_package_id', 'description', 'quantity', 'unit_price', 'amount'];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function connection()
    {
        return $this->belongsTo(CustomerConnection::class, 'customer_connection_id');
    }

    public function package()
    {
        return $this->belongsTo(InternetPackage::class, 'internet_package_id');
    }
}
