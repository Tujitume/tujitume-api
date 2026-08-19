# Tujitume API

> Backend powering the Tujitume Program & Impact Management SaaS platform.

---

## Overview

This repository contains the Laravel backend responsible for authentication, program workflows, business investment flow, service provider flow, reviewer management, milestone verification, payments, notifications, and deployment infrastructure.

---

## Backend Features

- RESTful API
- Sanctum Authentication
- Role & Permission Management
- Program Management
- Program Rounds
- Reviewer Assignment
- Milestone Workflow
- Business Investment
- Service Delivery
- Stripe Escrow Integration
- Lipr(Mpesa) Integration
- AWS S3 Storage
- Queue Processing
- Email Notifications
- Real-time Broadcasting

---

## Technical Highlights

- Laravel
- PHP
- MySQL
- Sanctum
- API Resources
- Form Requests
- Policies
- Queue Jobs
- Notifications
- Events & Listeners
- Service Layer
- DTO Pattern

---

## Infrastructure

- AWS EC2
- AWS S3
- GitHub Actions
- AWS CodeDeploy
- Apache
- Supervisor
- SSL

---

## CI/CD

Automated deployment pipeline using:

- GitHub Actions
- AWS CodeDeploy

Deployment includes

- Composer install
- Cache optimization
- Database migrations
- Queue restart
- Permission updates

---

## Project Structure

```text
app/
├── Actions
├── Data
├── DTOs
├── Events
├── Http
├── Jobs
├── Listeners
├── Models
├── Notifications
├── Policies
├── Services
```

---

## API

RESTful JSON API consumed by the React frontend.

---

## Related Repository

Frontend

https://github.com/md-nurul-kabir/tujitume

---

## Author

Md Nurul Kabir

Backend Engineer

Laravel • PHP • AWS