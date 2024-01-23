<?php

namespace Aoaite\SmtpTool\Connection;

enum SMTPEncryption
{
    case NONE;
    case SSL;
    case TLS;
    case STARTTLS;
}
