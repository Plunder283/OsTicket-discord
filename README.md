Discord Notifier for osTicket

Copy php files into there own directory called `discord` inside the plugins folder.

Installing the plugin:
![image](https://github.com/user-attachments/assets/3bad3e2c-6ce3-4009-ab14-d3055a572871)

Creating a new instance:
![image](https://github.com/user-attachments/assets/e5fa94ae-93d8-4dc6-af26-51efad92a80d)

New tickets will be posted to discord room with information summary.
The title of every notification embed links directly to the ticket in the
osTicket staff panel, using the Helpdesk URL configured in osTicket. Staff
must sign in and have permission to access the ticket.
![d8fbeabe4a941ef46e452d402c514450](https://github.com/user-attachments/assets/4357be42-99bb-4435-b7f4-49ff1a7cd41c)

## Notification triggers

Each instance can be configured independently to notify on any combination of:

- New ticket created
- New message from the requester on an existing ticket
- Staff reply posted on a ticket
- Ticket status changed (reopened, etc.)
- Ticket closed

Since osTicket has no dedicated signal for ticket status changes, this is
detected by listening to the ORM's `model.updated` signal on the `Ticket`
model and inspecting the dirty `status_id` field.

## Message templates

The message posted for each event type is fully customizable per instance.
The following placeholders are available in any template:

`{mention}` `{id}` `{number}` `{subject}` `{name}` `{email}` `{department}`
`{priority}` `{status}` `{old_status}` `{agent}` `{message}`

`{mention}` (e.g. `@here`, `@everyone`, or a role mention) is only applied to
the new-ticket message by default, but can be added manually to any other
template as well.
