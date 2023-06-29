<?php

namespace App\Notifications;

use App\Models\User;
use App\Models\Project;
use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use BeyondCode\SlackNotificationChannel\Channels\SlackApiChannel;
use BeyondCode\SlackNotificationChannel\Messages\SlackMessage;

class OverduePayment extends Notification implements ShouldQueue
{
    use Queueable;

    protected $project;

    public function __construct(Project $project)
    {
        $this->project = $project;
    }

    protected static function getNotificationList()
    {
        $payments = Payment::whereNull('paid_at')
            ->where('should_pay_at', '>=', now()->subMonths(3))
            ->get();

            $projects = Project::whereIn('contract_id', $payments->pluck('contract_id'))
            ->groupBy('name', 'id')
            ->get();        
        
        return $projects;
    }

    public function via(mixed $notifiable): array
    {
        return ['mail', SlackApiChannel::class];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $project = $this->project;
        $owner = User::find($project->owner_id);

        return (new MailMessage)
            ->subject('逾期款提醒：' . $project->name)
            ->line('專案名稱: ' . $project->name)
            ->line('本專案近3個月逾期款已達3筆，請協助追款，或衡量是否繼續執行此專案');
    }

    public function toSlack($notifiable): SlackMessage
    {
        $project = $this->project;
        $owner = User::find($project->owner_id);
        $slackUserId = $owner->slack_user_id;
        
        return (new SlackMessage)
            ->from(config('app.name'), ':ghost:')
            ->to($slackUserId)
            ->content('本專案近3個月逾期款已達3筆，請協助追款，或衡量是否繼續執行此專案')
            ->attachment(function ($attachment) use ($owner, $project) {
                $fields = [
                    'Project Name' => $project->name,
                ];
                $attachment->fields($fields);
            });
    }

    public static function sendNotifications()
    {
        $projects = self::getNotificationList();

        foreach ($projects as $project) {
            $owner = User::find($project->owner_id);
            if ($owner) {
                $notification = new OverduePayment($project);
                $owner->notify($notification);
            }
            
        }
    }
}
