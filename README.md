## Stream Local Plugin

The **Stream** local plugin adds automatic handling of online meeting recordings (for example, Zoom, Microsoft Teams, Unicko) and embeds them directly into Moodle courses, according to your configuration.

This document explains what the plugin does and how to use it, with a special focus on Zoom integration and automatic embedding.

---

## Main Features

- **Automatic recording discovery**
  - Connects to the configured video platform (Zoom / Teams / Unicko).
  - Periodically checks for new recordings that are ready.

- **Automatic embedding in Moodle courses**
  - Creates or reuses a Moodle activity (for example, Page / LTI / Zoom / msteams) to display the recording.
  - Places the recording in the correct course and section, based on the meeting data.
  - Can place the recording activity directly under the original meeting activity (for example, under the Zoom meeting).

- **De-duplication of recordings**
  - Identifies duplicate recordings (for example, same Zoom UUID) and marks them so they are not embedded multiple times.

- **Notifications to enrolled users (optional)**
  - Can send notifications to enrolled users in the course when a new recording is embedded.
  - Uses Moodle’s scheduled tasks and adhoc tasks to send messages.

---

## For Site Administrators

### Installation

1. Copy the plugin folder into `local/stream` in your Moodle codebase.
2. Visit *Site administration → Notifications* and follow the standard Moodle upgrade steps.
3. After installation, configure the plugin settings.

### Configuration

Go to:

- **Site administration → Plugins → Local plugins → Stream** (or similar link in the admin menu).

Key settings (names may vary slightly depending on your version):

- **Platform**
  - Choose the platform that provides the recordings:
    - `Zoom`
    - `Microsoft Teams`
    - `Unicko` (via LTI)

- **API / Integration settings**
  - For **Zoom**:
    - Enter the Zoom API credentials and required keys/secrets (JWT / OAuth / Server-to-server configuration, depending on your environment).
    - Make sure Zoom is allowed to provide recording data (cloud recordings).
  - For **Teams / Unicko**:
    - Configure the corresponding credentials / URLs / LTI settings.

- **Based grouping / duplicate handling**
  - Optionally group by unique meeting identifier (for example, Zoom UUID) to avoid embedding multiple versions of the same recording.

- **Embed order**
  - Controls whether the recording activity is moved under the original meeting activity (for example, the Zoom activity) or simply placed in the same section.

- **Scheduled task**
  - Ensure the scheduled task `local_stream\task\embed` is **enabled**:
    - Go to *Site administration → Server → Scheduled tasks*.
    - Find **Embedded recordings (local_stream)**.
    - Verify it is enabled and configured to run at appropriate intervals (for example, every 5–15 minutes, or hourly, depending on your needs).

### Zoom Integration – High-Level Flow

1. A teacher creates a **Zoom meeting** in a Moodle course (using the Zoom activity module).
2. The meeting is held and Zoom creates a **cloud recording**.
3. The Stream plugin’s scheduled task runs and:
   - Fetches the list of recordings from Zoom.
   - Matches each recording to the corresponding Moodle course and Zoom activity.
4. For each new recording:
   - The plugin creates a **Page (or other configured activity type)** containing the embedded recording link or player.
   - The plugin moves the new Page into the same course and section as the original Zoom activity.
   - Optionally, it moves the Page **directly under** the Zoom activity in the course section.
5. If notifications are enabled:
   - Enrolled users in the course receive a notification that a new recording is available.

---

## For Teachers / Course Managers

### What You Need To Do

In most cases, teachers do **not** need to manage the plugin directly. Typical workflow:

1. **Create a Zoom meeting in your course**
   - Add a new **Zoom** activity in the required course.
   - Configure time, topic, and other options as usual.

2. **Hold the meeting and record it in Zoom**
   - Make sure the meeting is set to be recorded to the **cloud**.

3. **Wait for automatic embedding**
   - After Zoom finishes processing the recording, the Stream plugin’s scheduled task will:
     - Detect the new recording.
     - Automatically create an activity in your Moodle course for the recording.
     - Place it in the same section as the original Zoom activity, and (depending on configuration) directly below it.

4. **Check your course page**
   - You should see a new resource/activity with the recording.
   - Students will see it in the course and may receive a notification, depending on the site configuration.

### Notes for Teachers

- You do **not** need to manually download / upload recordings from Zoom.
- If a recording does not appear:
  - Confirm that the meeting was recorded to the **cloud**.
  - Check with the site administrator that:
    - The plugin is installed and configured correctly.
    - The scheduled task `local_stream\task\embed` is running without errors.

---

## For Regular Users (Students)

- After your teacher records a Zoom session and the recording is ready:
  - The recording will appear automatically in the relevant course.
  - You usually will find it:
    - In the same section as the Zoom meeting, often directly under the meeting link.
- You may receive a notification in Moodle when a new recording is added (depends on site configuration).
- Just click the new recording activity to watch the session.

---

## Troubleshooting (Summary)

- **Recording not embedded**
  - Check that the scheduled task *Embedded recordings (local_stream)* is enabled and running.
  - For Zoom, verify that:
    - Cloud recordings are enabled.
    - API credentials are correct.

- **Recording in wrong course or section**
  - Confirm that the meeting topic / course mapping rules are correct (for Teams / Unicko flows).
  - Check plugin settings for how sections and order are chosen.

- **Duplicate recordings**
  - Make sure the “based grouping” setting is correctly configured so that multiple versions of the same Zoom recording are not embedded multiple times.

If problems continue, enable debugging in Moodle, run the `local_stream\task\embed` scheduled task manually (via CLI or scheduled tasks page), and review the log output for error messages.

# Stream Moodle Plugin

![Stream Logo](https://stream-platform.cloud/images/centricapp.svg)

[![Maintained by Mattan Dor (CentricApp)](https://img.shields.io/badge/Maintained%20by-Mattan%20Dor%20(CentricApp)-brightgreen)](https://centricapp.co.il)

Stream is a powerful Moodle plugin designed to synchronize and embed meetings from Zoom, Webex, and Teams directly into your Moodle courses. Enhance your online learning environment by seamlessly connecting with Stream, a comprehensive video platform optimized for academic use.

## Features

### Seamless Meeting Synchronization

- **Multi-Platform Integration**: Automatically sync meetings from Zoom, Webex, and Teams with your Moodle courses.
- **Effortless Setup**: Quick and easy configuration to start syncing meetings in no time.

### Automatic Meeting Embed

- **Instant Embedding**: Automatically embed meeting URLs into your Moodle courses, ensuring students always have access to the latest sessions.
- **Consistent Access**: Ensure all meetings are readily accessible within the Moodle course environment, streamlining the learning experience.

### Simplified Video Management

- **Centralized Management**: Manage all your video content from multiple platforms through a single, intuitive interface.
- **User-Friendly Controls**: Upload, edit, and organize your videos with ease, directly within Moodle.

### Professional Editing and Transcription

- **Advanced Editing Tools**: Utilize professional editing features to create polished educational videos.
- **Multilingual Transcription**: Automatic transcription available in 98 languages, promoting inclusivity and accessibility.

### Enhanced Educational Experience

- **Tailored for Academia**: Designed specifically for academic institutions, ensuring a smooth and intuitive user experience.
- **Collaborative Tools**: Facilitate collaboration and engagement through integrated communication channels and interactive features.

### Customization and Branding

- **Personalized Interface**: Customize the plugin interface to match your institution's branding and layout preferences.
- **Branded Learning Environment**: Create a cohesive digital space that reflects your organization's identity.

### Data-Driven Insights

- **Engagement Metrics**: Access real-time statistics and insights on video engagement to inform teaching strategies.
- **Continuous Improvement**: Utilize data-driven insights to enhance the educational experience continually.

## Installation and Setup

1. **Download and Install**: Obtain the Stream plugin from the Moodle plugins directory.
2. **Configuration**: Follow the detailed setup guide to configure the plugin for your institution's needs.
3. **Connect Platforms**: Link your Zoom, Webex, and Teams accounts to start syncing meetings.
4. **Embed and Enjoy**: Meetings will automatically be embedded into your Moodle courses, ready for immediate use.

## Support and Inquiries

For assistance with installation, configuration, or any other inquiries, please [contact us](https://stream-platform.cloud/) via our contact form or email. Our dedicated team is ready to provide personalized support to ensure a smooth integration experience.

**Stream © 2024**

---

Transform your Moodle environment with the Stream plugin and provide an enriched, streamlined educational experience for both instructors and students.