<?php

namespace App\Modules\Order\Mail;

use App\Modules\Order\Models\Order;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class OrderMail extends Mailable
{
    public const PLACED = 'placed';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const SHIPPED = 'shipped';

    public const DELIVERED = 'delivered';

    public const STAFF_NEW_ORDER = 'staff_new_order';

    /** @var array<string, array{subject: string, heading: string, intro: string}> */
    private const COPY = [
        self::PLACED => [
            'subject' => 'Order :number placed',
            'heading' => 'Thanks for your order',
            'intro' => 'We have received your order. Our team will review it and let you know once it is confirmed.',
        ],
        self::APPROVED => [
            'subject' => 'Order :number confirmed',
            'heading' => 'Your order is confirmed',
            'intro' => 'Your order has been approved and is being prepared for dispatch.',
        ],
        self::REJECTED => [
            'subject' => 'Order :number could not be fulfilled',
            'heading' => 'We could not fulfil your order',
            'intro' => 'Sorry, we are unable to fulfil this order. If you paid online, our team will contact you about the refund.',
        ],
        self::SHIPPED => [
            'subject' => 'Order :number shipped',
            'heading' => 'Your order is on its way',
            'intro' => 'Your order has been shipped.',
        ],
        self::DELIVERED => [
            'subject' => 'Order :number delivered',
            'heading' => 'Your order has been delivered',
            'intro' => 'Your order has been delivered. Thank you for shopping with us.',
        ],
        self::STAFF_NEW_ORDER => [
            'subject' => 'New order :number',
            'heading' => 'New order received',
            'intro' => 'A new order is waiting for approval.',
        ],
    ];

    public function __construct(public Order $order, public string $event) {}

    /** @return list<string> */
    public static function events(): array
    {
        return array_keys(self::COPY);
    }

    /** @var array{subject: string, heading: string, intro: string} */
    private const PARTLY_APPROVED = [
        'subject' => 'Order :number partly confirmed',
        'heading' => 'Your order is partly confirmed',
        'intro' => 'Some items in your order could not be fulfilled and have been cancelled. The rest is approved and being prepared for dispatch. If you paid online, our team will contact you about the refund for the cancelled items.',
    ];

    /** Escape customer-entered text so Markdown cannot turn it into links, lists or broken table rows. */
    public static function md(?string $text): string
    {
        $flat = preg_replace('/\s+/u', ' ', trim((string) $text)) ?? '';

        return addcslashes($flat, '\\`*_[](){}#+-.!|<>~');
    }

    /** @return array{subject: string, heading: string, intro: string} */
    private function copy(): array
    {
        if ($this->event === self::APPROVED && $this->order->items->contains(fn ($i) => (int) $i->qty_cancelled > 0)) {
            return self::PARTLY_APPROVED;
        }

        return self::COPY[$this->event];
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: str_replace(':number', $this->order->number, $this->copy()['subject']));
    }

    public function content(): Content
    {
        $forStaff = $this->event === self::STAFF_NEW_ORDER;

        return new Content(markdown: 'mail.orders.status', with: [
            'order' => $this->order,
            'items' => $this->order->items,
            'heading' => $this->copy()['heading'],
            'intro' => $this->copy()['intro'],
            'md' => self::md(...),
            'forStaff' => $forStaff,
            'orderUrl' => $forStaff ? null : config('seo.storefront_url').'/profile/orders/'.rawurlencode($this->order->number),
        ]);
    }
}
