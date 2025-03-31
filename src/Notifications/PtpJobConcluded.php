<?php

namespace Biigle\Modules\Ptp\Notifications;

use Biigle\Report;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PtpJobConcluded extends Notification
{
    /**
     * The volume for which the PTP job was running.
     *
     * @var
     */
    protected $volumeName;

    /**
     * Create a new notification instance.
     *
     * @param Report $report
     * @return void
     */
    public function __construct(string $volumeName)
    {
        $this->volumeName = $volumeName;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        $settings = config('ptp.notifications.default_settings');

        if (config('ptp.notifications.allow_user_settings') === true) {
            $settings = $notifiable->getSettings('ptp_notifications', $settings);
        }

        if ($settings === 'web') {
            return ['database'];
        }

        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        $message = (new MailMessage)
            ->subject('Your Point To Polygon conversion Job has concluded succesfully')
            ->line("The Point To Polygon conversion for volume $this->volumeName has concluded successfully.");

        return $message;
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        $array = [
            'title' => 'Your Point To Polygon conversion Job has concluded succesfully',
            'message' => "The Point To Polygon conversion for volume $this->volume has concluded successfully.",
        ];

        return $array;
    }
}
